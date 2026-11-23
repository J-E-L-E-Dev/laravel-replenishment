<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Contracts\SaleSource;
use Asisnet\Replenishment\DTO\SaleData;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\Casts\ExactDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Agrega ventas observadas por calendario sin inferir agotamientos ni demanda potencial. */
final class ObservedDemandCalculator
{
    /** @var string Versión auditable de fórmulas, calendario y reglas de calidad. */
    public const VERSION = 'observed-sales-1.0';
    /** @var array<string> Componentes exactos de cada observación diaria. */
    public const QUANTITIES = ['gross_sales_units', 'returned_units', 'reversed_return_units', 'net_sales_units'];

    /**
     * Consume el origen una sola vez y publica únicamente después de completar lectura y unicidad.
     * @param SaleSource $source Fuente normalizada de sólo lectura.
     * @param string $productId Producto opaco.
     * @param string $locationId Ubicación explícita.
     * @param DateTimeImmutable $from Inicio inclusivo a medianoche.
     * @param DateTimeImmutable $until Fin exclusivo a medianoche.
     * @param string $timezone Zona operativa.
     * @return ObservedDemandData Serie y resúmenes; una lectura fallida deja todo el intervalo desconocido.
     * @throws InvalidArgumentException Si el intervalo o alcance son inválidos.
     */
    public function calculate(SaleSource $source, string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until, string $timezone): ObservedDemandData
    {
        [$from, $until] = $this->bounds($from, $until, $timezone);
        if ($productId === '' || $locationId === '') {
            throw new InvalidArgumentException('Producto y ubicación deben ser explícitos.');
        }
        $days = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $days[$date->format('Y-m-d')] = $this->emptyDay();
        }
        $identities = new EventIdentityIndex();
        $unit = null;
        try {
            foreach ($source->between($productId, $locationId, $from, $until) as $sale) {
                if (!$sale instanceof SaleData || $sale->productId !== $productId || $sale->locationId !== $locationId || $sale->id === '') {
                    throw new RuntimeException('scope_or_identity_mismatch');
                }
                $date = $sale->occurredAt->setTimezone(new DateTimeZone($timezone));
                if ($date < $from || $date >= $until) {
                    throw new RuntimeException('event_outside_interval');
                }
                $identities->add($sale->id);
                $key = $date->format('Y-m-d');
                $day = &$days[$key];
                $inventoryIssues = ['time_of_day_unknown', 'same_day_order_ambiguous', 'stock_delta_mismatch', 'opening_observation_missing', 'source_order_conflict', 'unit_changed'];
                foreach ($sale->movement?->issues ?? [] as $issue) {
                    $field = in_array($issue, $inventoryIssues, true) ? 'inventory_quality_issues' : 'quality_issues';
                    if (!in_array($issue, $day[$field], true)) {
                        $day[$field][] = $issue;
                    }
                }
                $baseUnit = $sale->baseUnit ?? $sale->movement?->baseUnit;
                $reason = null;
                if (!in_array($sale->kind, ['sale', 'return', 'return_reversal'], true)) {
                    $reason = 'event_kind_unverified';
                } elseif ($baseUnit === null || trim($baseUnit) === '' || ($sale->movement !== null && $sale->movement->baseUnit === null)) {
                    $reason = 'event_unit_unverified';
                }
                try {
                    $quantity = BigDecimal::of(ExactDecimal::normalize($sale->quantity));
                    if (($sale->kind === 'return' && $quantity->isPositive()) || (in_array($sale->kind, ['sale', 'return_reversal'], true) && $quantity->isNegative())) {
                        $reason = 'event_sign_inconsistent';
                    }
                    if ($sale->movement !== null && ($sale->movement->demandQuantity === null
                        || !BigDecimal::of($sale->movement->demandQuantity)->isEqualTo($quantity)
                        || $sale->movement->id !== $sale->id || $sale->movement->productId !== $productId
                        || $sale->movement->locationId !== $locationId || $sale->movement->occurredAt != $sale->occurredAt
                        || $sale->movement->baseUnit !== $baseUnit)) {
                        $reason = 'event_trace_inconsistent';
                    }
                } catch (\Throwable $error) {
                    $reason = 'event_quantity_invalid';
                }
                if ($reason !== null) {
                    $day = $this->incomplete($day, $reason);
                    unset($day);
                    continue;
                }
                if ($unit !== null && $unit !== $baseUnit) {
                    throw new RuntimeException('series_unit_inconsistent');
                }
                $unit = $baseUnit;
                $day['base_unit'] = $unit;
                if ($day['observation_status'] === 'complete') {
                    $field = ['sale' => 'gross_sales_units', 'return' => 'returned_units', 'return_reversal' => 'reversed_return_units'][$sale->kind];
                    $day[$field] = ExactDecimal::normalize((string) BigDecimal::of($day[$field])->plus($quantity->abs()));
                    $day['net_sales_units'] = ExactDecimal::normalize((string) BigDecimal::of($day['net_sales_units'])->plus($quantity));
                    $day['event_count']++;
                }
                unset($day);
            }
            $identities->finish();
        } catch (\Throwable $error) {
            unset($day);
            $reason = in_array($error->getMessage(), ['duplicate_identity', 'identity_storage_failure', 'scope_or_identity_mismatch', 'event_outside_interval', 'series_unit_inconsistent'], true)
                ? $error->getMessage() : 'source_failure';
            foreach ($days as &$day) {
                $day = $this->incomplete($day, $reason);
            }
            unset($day);
        } finally {
            $identities->close();
        }
        return $this->fromDays($productId, $locationId, $from, $until, $timezone, $days);
    }

    /**
     * Consolida una serie acotada ya calculada o leída de caché, marcando huecos como desconocidos.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Corte.
     * @param string $timezone Zona operativa.
     * @param array<string,array> $days Observaciones diarias normalizadas.
     * @param string $origin source o cache.
     * @param string $version Versión de la serie recibida.
     * @param array $statistics Perfil y ejecución de origen.
     * @param DateTimeImmutable|null $calculatedAt Instante original de cálculo.
     * @return ObservedDemandData Resultados auditables, incluidos límites de cobertura.
     */
    public function fromDays(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until, string $timezone, array $days, string $origin = 'source', string $version = self::VERSION, array $statistics = [], ?DateTimeImmutable $calculatedAt = null): ObservedDemandData
    {
        [$from, $until] = $this->bounds($from, $until, $timezone);
        if ($statistics !== [] && (($statistics['requested_start'] ?? null) !== $from->format('Y-m-d')
            || ($statistics['until'] ?? null) !== $until->format('Y-m-d'))) {
            // Una subserie, especialmente retrospectiva, no hereda totales situados fuera de sus límites.
            unset($statistics['coverage']);
            $statistics['requested_start'] = $from->format('Y-m-d');
            $statistics['effective_start'] = max($statistics['requested_start'], $statistics['effective_start']);
            $statistics['until'] = $until->format('Y-m-d');
        }
        $series = [];
        $units = [];
        $cacheTimes = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $day = $days[$key] ?? $this->incomplete($this->emptyDay(), 'day_not_observed');
            $day['quality_issues'] = array_values(array_unique($day['quality_issues']));
            $day['inventory_quality_issues'] = array_values(array_unique($day['inventory_quality_issues']));
            if ($day['base_unit'] !== null) {
                $units[$day['base_unit']] = true;
            }
            $series[$key] = $day;
            if (isset($day['cached_calculated_at'])) {
                $cacheTimes[] = $day['cached_calculated_at'];
            }
        }
        if (count($units) > 1) {
            foreach ($series as &$day) {
                $day = $this->incomplete($day, 'series_unit_inconsistent');
            }
            unset($day);
        }
        $summary = $this->period($series, $from, $until);
        $summary['cache_calculated_at_min'] = $cacheTimes === [] ? null : min($cacheTimes);
        $summary['cache_calculated_at_max'] = $cacheTimes === [] ? null : max($cacheTimes);
        $summary['unestimated'] = array_fill_keys(['stockout_days', 'available_days', 'lost_demand_units', 'recommended_safety_stock', 'reorder_point', 'recommended_minimum', 'recommended_maximum'], null);
        $windows = [];
        foreach ([30, 60, 90, 180, 365] as $length) {
            $start = $until->modify('-'.$length.' days');
            $current = $this->period($series, $start, $until);
            $previous = $this->period($series, $start->modify('-'.$length.' days'), $start);
            $difference = null;
            $percentage = null;
            if ($current['net_sales_units'] !== null && $previous['net_sales_units'] !== null) {
                $difference = ExactDecimal::normalize((string) BigDecimal::of($current['net_sales_units'])->minus($previous['net_sales_units']));
                if (BigDecimal::of($previous['net_sales_units'])->isPositive()) {
                    $percentage = (string) BigDecimal::of($difference)->multipliedBy(100)->dividedBy($previous['net_sales_units'], 6, RoundingMode::HALF_UP);
                }
            }
            $current['previous_period'] = $previous;
            $current['net_change_units'] = $difference;
            $current['net_change_percent'] = $percentage;
            $windows[$length] = $current;
        }
        $months = $this->calendar($series, $from, $until, false);
        $years = $this->calendar($series, $from, $until, true);
        $monthlySum = BigDecimal::zero();
        $fullMonths = 0;
        $monthlyValid = true;
        foreach ($months as $month) {
            if (!$month['partial_calendar_period']) {
                $fullMonths++;
                if ($month['net_sales_units'] === null) {
                    $monthlyValid = false;
                } else {
                    $monthlySum = $monthlySum->plus($month['net_sales_units']);
                }
            }
        }
        $summary['full_calendar_months'] = $fullMonths;
        $summary['average_monthly_net'] = $monthlyValid && $fullMonths > 0 ? $this->divide((string) $monthlySum, $fullMonths) : null;
        return new ObservedDemandData($productId, $locationId, $from, $until, $timezone, $calculatedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')), $series, $summary, $windows, $months, $years, $origin, $version, $statistics);
    }

    /**
     * Valida días calendario y acota cada análisis a diez años.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param string $timezone Zona operativa.
     * @return array{DateTimeImmutable,DateTimeImmutable} Límites normalizados.
     * @throws InvalidArgumentException Si el intervalo no es diario, creciente o excede diez años.
     */
    public function bounds(DateTimeImmutable $from, DateTimeImmutable $until, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $from = $from->setTimezone($zone); $until = $until->setTimezone($zone);
        if ($from >= $until || $until > $from->modify('+10 years') || $from->format('H:i:s.u') !== '00:00:00.000000' || $until->format('H:i:s.u') !== '00:00:00.000000') {
            throw new InvalidArgumentException('Use un intervalo creciente de días completos, de hasta diez años.');
        }
        return [$from, $until];
    }

    /**
     * Crea un día sin eventos después de una lectura completa.
     * @return array Observación inicial exacta.
     */
    private function emptyDay(): array
    {
        return array_fill_keys(self::QUANTITIES, '0.000000') + ['event_count' => 0, 'base_unit' => null, 'observation_status' => 'complete', 'quality_issues' => [], 'inventory_quality_issues' => []];
    }

    /**
     * Invalida cantidades sin publicar acumulaciones parciales.
     * @param array $day Día.
     * @param string $reason Causa.
     * @return array Observación desconocida.
     */
    private function incomplete(array $day, string $reason): array
    {
        foreach (self::QUANTITIES as $field) {
            $day[$field] = null;
        }
        $day['event_count'] = null;
        $day['observation_status'] = 'incomplete';
        if (!in_array($reason, $day['quality_issues'], true)) {
            $day['quality_issues'][] = $reason;
        }
        return $day;
    }

    /**
     * Calcula un período solicitado sin confundir cobertura parcial con ceros.
     * @param array<string,array> $days Serie diaria.
     * @param DateTimeImmutable $from Inicio solicitado.
     * @param DateTimeImmutable $until Fin solicitado.
     * @return array Componentes, conteos, promedios y calidad independiente de inventario.
     */
    private function period(array $days, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $totals = array_fill_keys(self::QUANTITIES, '0.000000');
        $complete = true; $observed = 0; $calendar = 0; $active = 0; $positive = 0; $events = 0;
        $salesIssues = []; $inventoryIssues = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $calendar++;
            $day = $days[$date->format('Y-m-d')] ?? null;
            if ($day !== null) {
                $salesIssues = array_merge($salesIssues, $day['quality_issues']);
                $inventoryIssues = array_merge($inventoryIssues, $day['inventory_quality_issues']);
            }
            if ($day === null || $day['observation_status'] !== 'complete') {
                $complete = false;
                if ($day === null) { $salesIssues[] = 'period_not_fully_observed'; }
                continue;
            }
            $observed++; $events += $day['event_count'];
            $active += (int) ($day['event_count'] > 0);
            $positive += (int) BigDecimal::of($day['gross_sales_units'])->isPositive();
            foreach (self::QUANTITIES as $field) {
                $totals[$field] = (string) BigDecimal::of($totals[$field])->plus($day[$field]);
            }
        }
        try {
            foreach ($totals as $field => $value) {
                $totals[$field] = $complete ? ExactDecimal::normalize($value) : null;
            }
        } catch (InvalidArgumentException $error) {
            $complete = false;
            $totals = array_fill_keys(self::QUANTITIES, null);
            $salesIssues[] = 'aggregate_precision_exceeded';
        }
        return $totals + ['from' => $from->format(DATE_ATOM), 'until' => $until->format(DATE_ATOM),
            'observation_status' => $complete ? 'complete' : 'incomplete', 'calendar_days' => $calendar, 'observed_days' => $observed,
            'event_count' => $complete ? $events : null, 'days_with_commercial_events' => $complete ? $active : null,
            'days_with_positive_sales' => $complete ? $positive : null, 'days_without_registered_sales' => $complete ? $calendar - $positive : null,
            'days_without_commercial_events' => $complete ? $calendar - $active : null,
            'average_daily_calendar_net' => $complete ? $this->divide($totals['net_sales_units'], $calendar) : null,
            'average_net_per_sales_day' => $complete && $positive > 0 ? $this->divide($totals['net_sales_units'], $positive) : null,
            'sales_frequency' => $complete ? $this->divide((string) $positive, $calendar) : null,
            'sales_quality_issues' => array_values(array_unique($salesIssues)), 'inventory_quality_issues' => array_values(array_unique($inventoryIssues))];
    }

    /**
     * Agrupa meses o años con límites parciales explícitos.
     * @param array $days Serie.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Corte.
     * @param bool $annual Selecciona años.
     * @return array Resúmenes por calendario.
     */
    private function calendar(array $days, DateTimeImmutable $from, DateTimeImmutable $until, bool $annual): array
    {
        $result = [];
        $cursor = $annual ? $from->setDate((int) $from->format('Y'), 1, 1) : $from->modify('first day of this month');
        while ($cursor < $until) {
            $next = $cursor->modify($annual ? '+1 year' : '+1 month');
            $start = $cursor < $from ? $from : $cursor; $end = $next > $until ? $until : $next;
            $row = $this->period($days, $start, $end);
            $row['partial_calendar_period'] = $start != $cursor || $end != $next;
            $result[$cursor->format($annual ? 'Y' : 'Y-m')] = $row;
            $cursor = $next;
        }
        return $result;
    }

    /**
     * Divide indicadores con seis decimales y redondeo explícito, sin float.
     * @param string $value Numerador.
     * @param int $count Denominador positivo.
     * @return string Cociente HALF_UP.
     */
    private function divide(string $value, int $count): string
    {
        return (string) BigDecimal::of($value)->dividedBy($count, 6, RoundingMode::HALF_UP);
    }
}

