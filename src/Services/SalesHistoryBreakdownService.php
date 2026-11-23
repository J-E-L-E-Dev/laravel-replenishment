<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Desglosa ventas observadas por calendario sin publicar totales parciales como definitivos. */
final class SalesHistoryBreakdownService
{
    /** Recibe agregadores existentes. @param ObservedDemandStore $store Caché propia. @param ObservedDemandQuery $query Lectura explícita del origen. @param ObservedDemandCalculator $calculator Agregación exacta ya validada. */
    public function __construct(private ObservedDemandStore $store, private ObservedDemandQuery $query, private ObservedDemandCalculator $calculator) {}

    /**
     * Lee un año o un mes bajo demanda; no persiste ni consulta automáticamente años anteriores.
     * @param string $product Producto autorizado por el consumidor.
     * @param string $location Destino autorizado.
     * @param int $year Año civil solicitado.
     * @param int|null $month Mes opcional; NULL devuelve los meses del año.
     * @param string $timezone Zona operativa.
     * @param DateTimeImmutable $cutoff Inicio del día operativo, exclusivo.
     * @param bool $source Autoriza expresamente lectura directa acotada; por defecto sólo caché.
     * @return array Resumen, hijos y procedencia con desconocidos preservados.
     * @throws InvalidArgumentException Si el período está fuera del calendario permitido o aún no comenzó.
     */
    public function read(string $product, string $location, int $year, ?int $month, string $timezone, DateTimeImmutable $cutoff, bool $source = false): array
    {
        if ($product === '' || $location === '' || $year < 1900 || $year > 9998 || ($month !== null && ($month < 1 || $month > 12))) {
            throw new InvalidArgumentException('Indique producto, destino y un año o mes civil válido.');
        }
        $zone = new DateTimeZone($timezone);
        $cutoff = $cutoff->setTimezone($zone)->setTime(0, 0);
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month ?? 1), $zone);
        $calendarEnd = $from->modify($month === null ? '+1 year' : '+1 month');
        $until = min($calendarEnd, $cutoff);
        if ($from >= $until) { throw new InvalidArgumentException('El período solicitado no contiene días operativos completados.'); }
        $data = $source ? $this->query->analyze($product, $location, $from, $until) : $this->store->read($product, $location, $from, $until, $timezone, snapshot: true);
        return $this->breakdown($data, $month !== null, $calendarEnd);
    }

    /**
     * Divide una serie acotada en meses o semanas lunes-domingo recortadas; cada día aparece una sola vez.
     * @param ObservedDemandData $data Serie normalizada; nunca hechos duplicados.
     * @param bool $weekly Solicita semanas de un único mes.
     * @param DateTimeImmutable $calendarEnd Fin natural del año o mes para distinguir períodos parciales.
     * @return array Totales comerciales, hijos y calidad, sin inferir disponibilidad.
     * @throws InvalidArgumentException Si el intervalo semanal atraviesa meses o excede un año.
     */
    public function breakdown(ObservedDemandData $data, bool $weekly, DateTimeImmutable $calendarEnd): array
    {
        if ($data->until > $data->from->modify('+1 year') || ($weekly && $data->from->format('Y-m') !== $data->until->modify('-1 day')->format('Y-m'))) {
            throw new InvalidArgumentException('Solicite como máximo un año o las semanas de un único mes.');
        }
        $rows = [];
        for ($from = $data->from; $from < $data->until; $from = $until) {
            $naturalEnd = $weekly ? $from->modify('+'.(8 - (int) $from->format('N')).' days') : $from->modify('first day of next month');
            $until = min($naturalEnd, $data->until);
            $part = $this->calculator->fromDays($data->productId, $data->locationId, $from, $until, $data->timezone, $data->days, $data->origin, $data->version, $data->statistics, $data->calculatedAt);
            $rows[] = $this->summary($part, $naturalEnd) + ['label' => $weekly ? $from->format('d/m').' – '.$until->modify('-1 day')->format('d/m') : $from->format('Y-m'),
                'clipped_week' => $weekly && ((int) $from->format('N') !== 1 || $until < $naturalEnd)];
        }
        return ['product_id' => $data->productId, 'location_id' => $data->locationId, 'signal' => 'verified_gross_sales',
            'origin' => $data->origin, 'statistics' => $data->statistics, 'timezone' => $data->timezone, 'summary' => $this->summary($data, $calendarEnd), 'rows' => $rows,
            'level' => $weekly ? 'weeks' : 'months', 'warnings' => ['La caché puede estar desactualizada respecto al origen.', 'Las ventas brutas observadas no acreditan demanda perdida ni disponibilidad histórica.']];
    }

    /**
     * Compara tasas diarias de períodos civiles completos, de igual unidad y cobertura íntegra.
     * @param array $current Resumen actual.
     * @param array $previous Resumen anterior.
     * @return string|null Variación porcentual exacta a seis decimales; NULL ante desconocidos o denominador cero.
     */
    public function variation(array $current, array $previous): ?string
    {
        foreach ([$current, $previous] as $period) {
            if (($period['observation_status'] ?? null) !== 'complete' || ($period['partial_calendar_period'] ?? true) || empty($period['base_unit']) || ($period['calendar_days'] ?? 0) < 1 || ($period['gross_sales_units'] ?? null) === null) { return null; }
        }
        if ($current['base_unit'] !== $previous['base_unit'] || !BigDecimal::of($previous['gross_sales_units'])->isPositive()) { return null; }
        $numerator = BigDecimal::of($current['gross_sales_units'])->multipliedBy($previous['calendar_days']);
        $denominator = BigDecimal::of($previous['gross_sales_units'])->multipliedBy($current['calendar_days']);
        return (string) $numerator->minus($denominator)->multipliedBy(100)->dividedBy($denominator, 6, RoundingMode::HALF_UP);
    }

    /** Resume un período y su unidad comprobada. @param ObservedDemandData $data Serie. @param DateTimeImmutable $end Fin civil natural. @return array Componentes FAC/NCC/NDC, neto y días desconocidos. */
    private function summary(ObservedDemandData $data, DateTimeImmutable $end): array
    {
        $units = [];
        foreach ($data->days as $day) { if (($day['base_unit'] ?? null) !== null) { $units[$day['base_unit']] = true; } }
        return $data->summary + ['unknown_days' => $data->summary['calendar_days'] - $data->summary['observed_days'],
            'partial_calendar_period' => $data->until < $end, 'base_unit' => count($units) === 1 ? array_key_first($units) : null];
    }
}
