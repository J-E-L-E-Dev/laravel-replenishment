<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Asisnet\Replenishment\DTO\CommercialSalesData;
use Asisnet\Replenishment\DTO\InventoryMovementData;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Lee la fuente comercial por identidad de movimiento y agrega exclusivamente en la unidad histórica. */
final class CommercialSalesQuery
{
    /** @param AdapterRegistry $adapters Fuentes configuradas. @param CommercialProfile $profiles Identidad semántica.
     * @param ObservedDemandCalculator $calendar Validación del calendario. */
    public function __construct(private AdapterRegistry $adapters, private CommercialProfile $profiles, private ObservedDemandCalculator $calendar) {}

    /**
     * Consume completamente el iterable antes de acreditar días sin ventas o permitir persistencia.
     * @param string $product Producto solicitado.
     * @param string $location Ubicación lógica.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param string $timezone Zona operativa.
     * @return CommercialSalesData Serie con incertidumbre explícita ante fallo del origen.
     * @throws InvalidArgumentException Ante ámbito o intervalo inválido.
     */
    public function read(string $product, string $location, DateTimeImmutable $from, DateTimeImmutable $until, string $timezone): CommercialSalesData
    {
        [$from, $until] = $this->calendar->bounds($from, $until, $timezone);
        if ($product === '' || $location === '' || $until > CarbonImmutable::now($timezone)->startOfDay()) {
            throw new InvalidArgumentException('Use producto, ubicación y días operativos completos.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $profile = $this->profiles->describe($product, $location, $timezone, null, null);
        $effective = $from;
        $index = new EventIdentityIndex();
        try {
            $master = $this->adapters->resolve('products')->find($product);
            if ($master !== null && $master->id !== $product) {
                throw new RuntimeException('product_identity_mismatch');
            }
            $window = $this->adapters->resolve('commercial_history')->window($product, $location, $from, $until);
            $cutoff = $window->cutoffDate;
            $start = $from;
            if ($cutoff !== null) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new DateTimeZone($timezone));
                if (!$date || $date->format('Y-m-d') !== $cutoff) {
                    throw new RuntimeException('invalid_statistical_cutoff');
                }
                $start = max($from, $date->modify('+1 day'));
            }
            if ($window->requestedStart != $from || $window->until != $until || $window->effectiveStart != $start
                || ($master?->statisticsCutoffDate !== null && $master->statisticsCutoffDate !== $cutoff)) {
                throw new RuntimeException('source_window_mismatch');
            }
            $effective = $start;
            $unit = $master?->stockUnit;
            $profile = $this->profiles->describe($product, $location, $timezone, trim($unit ?? '') === '' ? null : $unit, $cutoff);
            $timing = (bool)config('replenishment.performance_log');
            $start = $timing ? hrtime(true) : 0;
            $count = 0;
            $days = self::calendarDays($from, $effective, $until, 'zero_observed');
            $elapsed = $timing ? hrtime(true)-$start : 0;
            $status = 'complete';
            foreach ($window->movements as $movement) {
                if (!$movement instanceof InventoryMovementData || trim($movement->id) === '' || $movement->productId !== $product
                    || $movement->locationId !== $location || $movement->occurredAt < $effective || $movement->occurredAt >= $until) {
                    throw new RuntimeException('invalid_movement_scope_or_identity');
                }
                if ($timing) { $start = hrtime(true); }
                $index->add($movement->id);
                $key = $movement->occurredAt->setTimezone($from->getTimezone())->format('Y-m-d');
                $this->accumulate($days[$key], $movement);
                if ($timing) { $elapsed += hrtime(true)-$start; $count++; }
                if ($days[$key]['state'] === 'incomplete') {
                    $status = 'incomplete';
                }
            }
            if ($timing) { $start = hrtime(true); }
            $index->finish();
            if ($timing) { app(PerformanceTrace::class)->duration('daily_aggregation',$elapsed+(hrtime(true)-$start),$status,['product'=>$product,'records'=>$count,'days'=>count($days)]); }
            return new CommercialSalesData($profile, $from, $effective, $until, $now, $days, $status);
        } catch (\Throwable $error) {
            // Los mensajes de drivers pueden contener SQL o credenciales. Sólo se publican diagnósticos conocidos.
            $reason = in_array($error->getMessage(), ['duplicate_identity','invalid_movement_scope_or_identity',
                'source_window_mismatch','invalid_statistical_cutoff','product_identity_mismatch'], true)
                ? $error->getMessage() : 'commercial_source_failed';
            if ($reason === 'commercial_source_failed') {
                $reason = match (true) {
                    preg_match('/timeout|timed out|HYT0[01]/i', $error->getMessage()) === 1 => 'source_timeout',
                    str_starts_with((string)$error->getCode(),'08') => 'source_connection_failed',
                    default => $reason,
                };
            }
            return new CommercialSalesData($profile, $from, $effective, $until, $now,
                self::calendarDays($from, $effective, $until, 'unknown'), 'unknown', limitation: $reason);
        } finally {
            $index->close();
        }
    }

    /** Crea sólo observaciones de cobertura. @param DateTimeImmutable $from Inicio. @param DateTimeImmutable $effective Inicio efectivo.
     * @param DateTimeImmutable $until Fin exclusivo. @param string $state Estado dentro de ventana. @return array Días, sin filas ficticias de unidad. */
    public static function calendarDays(DateTimeImmutable $from, DateTimeImmutable $effective, DateTimeImmutable $until, string $state): array
    {
        $days = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $days[$date->format('Y-m-d')] = [
                'state' => $date < $effective ? 'outside_cutoff' : $state,
                'event_count' => $date < $effective || $state === 'unknown' ? null : 0,
                'issues' => [], 'units' => [],
            ];
        }
        return $days;
    }

    /** Acumula una observación; preserva subtotales identificados si otra línea invalida el día.
     * @param array $day Día civil por referencia. @param InventoryMovementData $event Detalle normalizado. @return void */
    private function accumulate(array &$day, InventoryMovementData $event): void
    {
        $day['event_count']++;
        $day['issues'] = array_values(array_unique([...$day['issues'], ...$event->issues]));
        $unit = $event->historicalSaleUnit;
        $field = ['FAC' => 'gross_sales_units', 'NCC' => 'returned_units', 'NDC' => 'reversed_return_units'][$event->transactionType] ?? null;
        try {
            if ($field === null || trim($unit ?? '') === '' || $event->historicalSaleQuantity === null) {
                throw new InvalidArgumentException('commercial_movement_unverified');
            }
            $value = BigDecimal::of(ExactDecimal::normalize($event->historicalSaleQuantity));
            if ($field === 'returned_units' ? !$value->isNegative() : !$value->isPositive()) {
                throw new InvalidArgumentException('commercial_sign_invalid');
            }
            $bucket = $day['units'][$unit] ?? (array_fill_keys(ObservedDemandCalculator::QUANTITIES, '0.000000') + ['unit' => $unit, 'event_count' => 0]);
            $magnitude = $field === 'returned_units' ? $value->negated() : $value;
            $bucket[$field] = ExactDecimal::normalize((string) BigDecimal::of($bucket[$field])->plus($magnitude));
            $bucket['net_sales_units'] = ExactDecimal::normalize((string) BigDecimal::of($bucket['net_sales_units'])->plus($value));
            $bucket['event_count']++;
            $day['units'][$unit] = $bucket;
            if ($day['state'] !== 'incomplete') {
                $day['state'] = 'complete';
            }
        } catch (InvalidArgumentException $error) {
            $day['state'] = 'incomplete';
            $reason = $error->getMessage() === 'commercial_sign_invalid' ? 'commercial_sign_invalid' : 'commercial_movement_unverified';
            $day['issues'] = array_values(array_unique([...$day['issues'], $reason]));
        }
    }
}
