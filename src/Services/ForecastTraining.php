<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\ForecastPeriodData;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Prepara meses verificables y cortes de entrenamiento sin completar huecos con ceros. */
final class ForecastTraining
{
    /**
     * Reutiliza agregación y estacionalidad aprobadas.
     * @param ObservedDemandCalculator $calculator Agregador comercial.
     * @param SeasonalityAnalyzer $seasonality Analizador mensual.
     */
    public function __construct(private ObservedDemandCalculator $calculator, private SeasonalityAnalyzer $seasonality) {}
    /**
     * Exige límites mensuales completos y conserva las exclusiones de cada mes.
     * @param ObservedDemandData $source Serie original, sin datos posteriores al corte.
     * @return array<ForecastPeriodData> Meses ordenados, incluidos desconocidos.
     * @throws InvalidArgumentException Si el intervalo no corresponde a meses completos ya terminados.
     */
    public function periods(ObservedDemandData $source): array
    {
        [$from, $until] = $this->calculator->bounds($source->from, $source->until, $source->timezone);
        $now = (new DateTimeImmutable('now', new DateTimeZone($source->timezone)))->modify('first day of this month')->setTime(0, 0);
        if ($from->format('d') !== '01' || $until->format('d') !== '01' || $until > $now || $source->productId === '' || $source->locationId === '') {
            throw new InvalidArgumentException('El entrenamiento requiere producto, ubicación y límites en el primer día de meses completos; --until es el corte exclusivo.');
        }
        $result = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 month')) {
            $end = $date->modify('+1 month'); $key = $date->format('Y-m'); $month = $source->months[$key] ?? null; $issues = []; $units = [];
            if ($month === null || $month['partial_calendar_period'] || $month['observation_status'] !== 'complete' || $month['gross_sales_units'] === null) { $issues[] = 'unknown_or_partial_month'; }
            for ($day = $date; $day < $end; $day = $day->modify('+1 day')) {
                $row = $source->days[$day->format('Y-m-d')] ?? null;
                if ($row === null || $row['observation_status'] !== 'complete') { $issues[] = 'unknown_day'; continue; }
                $unit = $row['base_unit'] ?? null;
                if (is_string($unit) && trim($unit) !== '') { $units[$unit] = true; }
                elseif ($row['gross_sales_units'] !== null && BigDecimal::of($row['gross_sales_units'])->isPositive()) { $issues[] = 'unit_unverified'; }
            }
            if (count($units) > 1) { $issues[] = 'incompatible_units'; }
            $quantity = $issues === [] ? $month['gross_sales_units'] : null;
            if ($quantity !== null && BigDecimal::of($quantity)->isNegative()) { $quantity = null; $issues[] = 'invalid_gross_sales'; }
            $result[] = new ForecastPeriodData($date, $end, (int) $date->diff($end)->format('%a'), $quantity, count($units) === 1 ? (string) array_key_first($units) : null,
                array_values(array_unique(array_merge($issues, $month['sales_quality_issues'] ?? []))),
                array_intersect_key($month ?? [], array_flip(['returned_units', 'reversed_return_units', 'net_sales_units'])));
        }
        return $result;
    }
    /**
     * Obtiene sólo el tramo consecutivo conocido que termina justo en el origen.
     * @param array<ForecastPeriodData> $prefix Meses anteriores.
     * @return array<ForecastPeriodData> Tramo comparable; nunca salta un mes desconocido.
     */
    public function suffix(array $prefix): array
    {
        $result = []; $unit = null; $next = null;
        foreach (array_reverse($prefix) as $row) {
            if ($row->quantity === null || ($next !== null && $row->until != $next) || ($unit !== null && $row->unit !== null && $unit !== $row->unit)) { break; }
            if ($row->unit !== null) { $unit = $row->unit; }
            $result[] = $row; $next = $row->from;
        }
        return array_reverse($result);
    }
    /**
     * Recalcula factores sólo con días del entrenamiento; no reutiliza factores calculados después.
     * @param ObservedDemandData $source Serie origen.
     * @param array<ForecastPeriodData> $training Tramo verificado.
     * @param SeasonalityOptions $options Evidencia exigida.
     * @return array|null Auditoría estacional limitada al ajuste.
     */
    public function seasonal(ObservedDemandData $source, array $training, SeasonalityOptions $options): ?array
    {
        if (count($training) < $options->minimumYears * 12) { return null; }
        $from = $training[0]->from; $until = $training[count($training) - 1]->until;
        $days = array_filter($source->days, fn (string $key): bool => $key >= $from->format('Y-m-d') && $key < $until->format('Y-m-d'), ARRAY_FILTER_USE_KEY);
        $slice = $this->calculator->fromDays($source->productId, $source->locationId, $from, $until, $source->timezone, $days, $source->origin, $source->version, $source->statistics, $source->calculatedAt);
        return $this->seasonality->analyze($slice, $options)->audit();
    }
}
