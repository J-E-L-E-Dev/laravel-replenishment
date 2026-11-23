<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\SeasonalityData;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;

/** Detecta concentración mensual de ventas observadas separando escala anual, recurrencia e incertidumbre. */
final class SeasonalityAnalyzer
{
    /** @var string Versión de fórmulas, selección y clasificación. */
    public const VERSION = 'observed-seasonality-1.0';

    /**
     * Consolida sólo años completos comparables; conserva meses excluidos y magnitudes complementarias.
     * @param ObservedDemandData $source Serie normalizada del mismo producto y ubicación.
     * @param SeasonalityOptions $options Criterios publicados sin umbrales ocultos.
     * @return SeasonalityData Diagnóstico sin escrituras ni alteración de ventas.
     */
    public function analyze(ObservedDemandData $source, SeasonalityOptions $options): SeasonalityData
    {
        $months = []; $years = []; $units = []; $unverifiedUnitMonths = [];
        foreach ($source->days as $date => $day) {
            $unit = $day['base_unit'] ?? null;
            if (is_string($unit) && trim($unit) !== '') { $units[$unit] = true; }
            elseif ($day['gross_sales_units'] !== null && BigDecimal::of($day['gross_sales_units'])->isPositive()) { $unverifiedUnitMonths[substr($date, 0, 7)] = true; }
        }
        $currentMonth = (new DateTimeImmutable('now', new DateTimeZone($source->timezone)))->modify('first day of this month')->setTime(0, 0);
        $latestYear = (int) $source->until->modify('-1 day')->format('Y');
        $cutoff = $latestYear - $options->historyYears + 1;
        foreach ($source->months as $key => $month) {
            $year = (int) substr($key, 0, 4); $number = (int) substr($key, 5, 2);
            $reasons = [];
            if ($month['partial_calendar_period']) { $reasons[] = 'partial_month'; }
            if (new DateTimeImmutable($month['until']) > $currentMonth) { $reasons[] = 'unfinished_month'; }
            if ($month['observation_status'] !== 'complete' || $month['gross_sales_units'] === null) { $reasons[] = 'unknown_month'; }
            if (isset($unverifiedUnitMonths[$key]) || (count($units) !== 1 && $month['gross_sales_units'] !== '0.000000')) { $reasons[] = 'unit_unverified'; }
            $months[$key] = ['year' => $year, 'month' => $number, 'days' => $month['calendar_days'],
                'gross_sales_units' => $month['gross_sales_units'], 'returned_units' => $month['returned_units'],
                'reversed_return_units' => $month['reversed_return_units'], 'net_sales_units' => $month['net_sales_units'],
                'positive_sales_days' => $month['days_with_positive_sales'], 'status' => $reasons === [] ? 'complete' : 'excluded',
                'reasons' => array_values(array_unique(array_merge($reasons, $month['sales_quality_issues']))), 'index' => null,
                'daily_rate' => $reasons === [] ? $this->divide($month['gross_sales_units'], (string) $month['calendar_days']) : null];
            $years[$year]['months'][$number] = $key;
        }
        $accepted = []; $excluded = [];
        foreach ($years as $year => &$entry) {
            $reasons = []; $total = BigDecimal::zero(); $days = 0; $activeDays = 0;
            if (count($entry['months']) !== 12) { $reasons[] = 'partial_year'; }
            if ($year < $cutoff) { $reasons[] = 'outside_history_policy'; }
            foreach ($entry['months'] as $key) {
                $days += $months[$key]['days'];
                if ($months[$key]['status'] !== 'complete') { $reasons[] = 'unknown_or_partial_months'; }
                else { $total = $total->plus($months[$key]['gross_sales_units']); $activeDays += $months[$key]['positive_sales_days']; }
            }
            $complete = count($entry['months']) === 12 && !in_array('unknown_or_partial_months', $reasons, true);
            if ($complete && $total->isZero()) { $reasons[] = 'zero_annual_activity'; }
            elseif ($complete && $activeDays < $options->minimumActiveDays) { $reasons[] = 'insufficient_active_days'; }
            if (count($units) !== 1 && !$total->isZero()) { $reasons[] = 'unit_unverified'; }
            $entry += ['gross_sales_units' => $complete ? (string) $total : null, 'calendar_days' => $days, 'positive_sales_days' => $complete ? $activeDays : null,
                'daily_rate' => $complete ? $this->divide((string) $total, (string) $days) : null,
                'status' => $reasons === [] ? 'accepted' : 'excluded', 'reasons' => array_values(array_unique($reasons))];
            if ($reasons !== []) { $excluded[$year] = $entry['reasons']; continue; }
            $accepted[] = $year;
            foreach ($entry['months'] as $key) {
                // Dividir una sola vez evita que el redondeo de tasas diarias altere el índice.
                $months[$key]['index'] = $this->divide((string) BigDecimal::of($months[$key]['gross_sales_units'])->multipliedBy($days),
                    (string) $total->multipliedBy($months[$key]['days']));
            }
        }
        unset($entry);
        $monthly = []; $sufficient = count($accepted) >= $options->minimumYears;
        for ($number = 1; $number <= 12; $number++) {
            $values = []; $high = []; $low = []; $totals = []; $rates = [];
            foreach ($accepted as $year) {
                $month = $months[$years[$year]['months'][$number]]; $values[$year] = $month['index'];
                $totals[] = $month['gross_sales_units']; $rates[] = $month['daily_rate'];
                if (BigDecimal::of($month['index'])->isGreaterThanOrEqualTo($options->highIndex)) { $high[] = $year; }
                if (BigDecimal::of($month['index'])->isLessThanOrEqualTo($options->lowIndex)) { $low[] = $year; }
            }
            $median = $this->median(array_values($values)); $spread = $values === [] ? null : (string) $this->maximum($values)->minus($this->minimum($values));
            $frequency = $values === [] ? null : $this->divide((string) count($high), (string) count($values));
            $quality = 'insufficient';
            if ($sufficient) {
                $quality = 'none_detected';
                if ($high !== []) {
                    $quality = count($high) === 1 ? 'possible' : (BigDecimal::of($spread)->isGreaterThan($options->maximumSpread) ? 'unstable' : 'possible');
                    if (BigDecimal::of($median)->isGreaterThanOrEqualTo($options->highIndex) && BigDecimal::of($frequency)->isGreaterThanOrEqualTo($options->recurrence)
                        && !BigDecimal::of($spread)->isGreaterThan($options->maximumSpread)) { $quality = 'recurrent'; }
                }
            } elseif ($high !== []) { $quality = 'possible'; }
            $lowFrequency = $values === [] ? null : $this->divide((string) count($low), (string) count($values));
            if ($sufficient && $median !== null && BigDecimal::of($median)->isLessThanOrEqualTo($options->lowIndex)) {
                $quality = BigDecimal::of($spread)->isGreaterThan($options->maximumSpread) ? 'unstable' : 'possible';
                if (BigDecimal::of($lowFrequency)->isGreaterThanOrEqualTo($options->recurrence) && !BigDecimal::of($spread)->isGreaterThan($options->maximumSpread)) { $quality = 'recurrent'; }
            }
            $leaveOneOut = [];
            if (count($values) > 1) { foreach (array_keys($values) as $year) { $copy = $values; unset($copy[$year]); $leaveOneOut[$year] = $this->median(array_values($copy)); } }
            $above = count(array_filter($values, fn (string $v): bool => BigDecimal::of($v)->isGreaterThan(1)));
            $p = $values === [] ? null : $this->signTail(count($values), $above);
            $monthly[$number] = ['index' => $sufficient ? $median : null, 'exploratory_index' => $median, 'quality' => $quality,
                'level' => $median === null ? null : (BigDecimal::of($median)->isGreaterThanOrEqualTo($options->highIndex) ? 'high' : (BigDecimal::of($median)->isLessThanOrEqualTo($options->lowIndex) ? 'low' : 'usual')),
                'mean_observed_units' => $totals === [] ? null : $this->average($totals), 'median_daily_rate' => $this->median($rates),
                'indices_by_year' => $values, 'high_years' => $high, 'non_high_years' => array_values(array_diff($accepted, $high)), 'low_years' => $low,
                'spread' => $spread, 'leave_one_year_out' => $leaveOneOut, 'high_recurrence' => $frequency, 'low_recurrence' => $lowFrequency,
                'sign_test_p' => $p, 'significant_after_12_tests' => $sufficient && $p !== null && BigDecimal::of($p)->multipliedBy(12)->isLessThanOrEqualTo($options->alpha), 'confidence' => null];
        }
        $trend = $this->trend($accepted, $years, $months, $options);
        if ($trend['within_year_monotonic_years'] !== [] && count($trend['within_year_monotonic_years']) === count($accepted)) {
            foreach ($monthly as &$month) { if ($month['quality'] === 'recurrent') { $month['quality'] = 'possible'; } } unset($month);
        }
        $qualities = array_column($monthly, 'quality');
        $quality = !$sufficient ? 'insufficient' : (in_array('unstable', $qualities, true) ? 'unstable' : (in_array('recurrent', $qualities, true) ? 'recurrent' : (in_array('possible', $qualities, true) ? 'possible' : 'none_detected')));
        $peaks = []; $peak = null;
        foreach ($monthly as $number => $month) { if ($month['index'] === null) { continue; } if ($peak === null || BigDecimal::of($month['index'])->isGreaterThan($peak)) { $peak = $month['index']; $peaks = [$number]; } elseif (BigDecimal::of($month['index'])->isEqualTo($peak)) { $peaks[] = $number; } }
        return new SeasonalityData($source, ['product_id' => $source->productId, 'location_id' => $source->locationId,
            'from' => $source->from->format(DATE_ATOM), 'until' => $source->until->format(DATE_ATOM), 'timezone' => $source->timezone,
            'calculated_at' => $source->calculatedAt->format(DATE_ATOM), 'algorithm_version' => self::VERSION, 'signal' => 'verified_gross_sales', 'origin' => $source->origin,
            'source_algorithm_version' => $source->version, 'source_statistics' => $source->statistics, 'cache_calculated_at_min' => $source->summary['cache_calculated_at_min'] ?? null,
            'cache_calculated_at_max' => $source->summary['cache_calculated_at_max'] ?? null,
            'source_reading_status' => $source->summary['observation_status'], 'source_quality_issues' => $source->summary['sales_quality_issues'],
            'options' => $options->audit(), 'base_unit' => count($units) === 1 ? array_key_first($units) : null,
            'analyzed_years' => array_keys($years), 'accepted_years' => $accepted, 'excluded_years' => $excluded, 'quality' => $quality,
            'months' => $monthly, 'observed_months' => $months, 'years' => $years, 'seasons' => $this->seasons($monthly, $accepted, $options),
            'peak_month' => count($peaks) === 1 ? $peaks[0] : null, 'peak_months' => $peaks, 'trend' => $trend, 'confidence' => null,
            'warnings' => ['Ventas observadas, no demanda potencial; agotamientos históricos no identificados podrían reducir ventas.',
                'Disponibilidad comercial histórica y demanda perdida desconocidas; no se corrigen ventas.',
                'Recurrencia descriptiva no equivale a significación estadística ni demuestra causas externas.',
                'Los años excluidos y la ventana seleccionada pueden limitar la representatividad comercial.']]);
    }

    /**
     * Evalúa tendencias anuales y cambios mensuales sin atribuir causas.
     * @param array $accepted Años válidos.
     * @param array $years Magnitudes anuales.
     * @param array $months Meses observados.
     * @param SeasonalityOptions $options Umbral de anomalía.
     * @return array Diagnóstico separado.
     */
    private function trend(array $accepted, array $years, array $months, SeasonalityOptions $options): array
    {
        $rates = []; foreach ($accepted as $year) { $rates[] = $years[$year]['daily_rate']; }
        $median = $this->median($rates); $outliers = []; $changes = []; $monthlyChanges = []; $monotonic = []; $previous = null;
        foreach ($accepted as $year) {
            $rate = $years[$year]['daily_rate'];
            if (BigDecimal::of($rate)->isGreaterThan(BigDecimal::of($median)->multipliedBy($options->outlierFactor))
                || BigDecimal::of($rate)->multipliedBy($options->outlierFactor)->isLessThan($median)) { $outliers[] = $year; }
            if ($previous !== null) { $changes[$year] = ['previous_year' => $previous, 'rate_ratio' => $this->divide($rate, $years[$previous]['daily_rate']), 'consecutive' => $year === $previous + 1]; }
            $up = true; $down = true; $last = null;
            foreach ($years[$year]['months'] as $number => $key) {
                $value = $months[$key]['daily_rate'];
                if ($last !== null) { $up = $up && BigDecimal::of($value)->isGreaterThan($last); $down = $down && BigDecimal::of($value)->isLessThan($last); }
                $last = $value;
                if (isset($years[$year - 1]) && in_array($year - 1, $accepted, true)) {
                    $old = $months[$years[$year - 1]['months'][$number]]['daily_rate'];
                    $monthlyChanges[$key] = BigDecimal::of($old)->isPositive() ? $this->divide($value, $old) : null;
                }
            }
            if ($up || $down) { $monotonic[] = $year; }
            $previous = $year;
        }
        $up = $changes !== []; $down = $up;
        foreach ($changes as $change) { $up = $up && $change['consecutive'] && BigDecimal::of($change['rate_ratio'])->isGreaterThan(1); $down = $down && $change['consecutive'] && BigDecimal::of($change['rate_ratio'])->isLessThan(1); }
        return ['classification' => count($accepted) < 2 ? 'insufficient' : ($up ? 'increasing' : ($down ? 'decreasing' : 'stable_or_mixed')),
            'annual_rate_changes' => $changes, 'monthly_year_over_year_rate_ratios' => $monthlyChanges, 'atypical_scale_years' => $outliers,
            'within_year_monotonic_years' => $monotonic, 'outliers_removed' => false];
    }

    /**
     * Agrupa meses altos contiguos y verifica años reales cuando una temporada cruza diciembre.
     * @param array $months Índices consolidados.
     * @param array $accepted Años comparables.
     * @param SeasonalityOptions $options Recurrencia exigida.
     * @return array Temporadas descriptivas, nunca pronósticos.
     */
    private function seasons(array $months, array $accepted, SeasonalityOptions $options): array
    {
        $high = []; foreach ($months as $number => $month) { if (in_array($month['quality'], ['recurrent', 'possible', 'unstable'], true) && $month['level'] === 'high') { $high[] = $number; } }
        if ($high === [] || count($high) === 12) { return []; }
        $result = [];
        foreach ($high as $start) {
            if (in_array($start === 1 ? 12 : $start - 1, $high, true)) { continue; }
            $group = []; $cursor = $start;
            while (in_array($cursor, $high, true)) { $group[] = $cursor; $cursor = $cursor === 12 ? 1 : $cursor + 1; }
            $end = $group[count($group) - 1]; $wraps = $end < $start; $present = []; $absent = []; $unassessed = [];
            foreach ($accepted as $year) {
                if ($wraps && !in_array($year + 1, $accepted, true)) { $unassessed[] = $year; continue; }
                $matches = true;
                foreach ($group as $month) { $target = $wraps && $month < $start ? $year + 1 : $year; $matches = $matches && in_array($target, $months[$month]['high_years'], true); }
                if ($matches) { $present[] = $year; } else { $absent[] = $year; }
            }
            $quality = count(array_filter($group, fn (int $m): bool => $months[$m]['quality'] === 'unstable')) > 0 ? 'unstable' : 'possible';
            $eligible = count($present) + count($absent);
            if (count($present) >= $options->minimumYears && $eligible > 0 && BigDecimal::of($this->divide((string) count($present), (string) $eligible))->isGreaterThanOrEqualTo($options->recurrence)
                && count(array_filter($group, fn (int $m): bool => $months[$m]['quality'] !== 'recurrent')) === 0) { $quality = 'recurrent'; }
            $indices = []; foreach ($group as $month) { $indices[$month] = $months[$month]['exploratory_index']; }
            $max = $this->maximum($indices); $peaks = array_keys(array_filter($indices, fn (string $v): bool => BigDecimal::of($v)->isEqualTo($max)));
            $result[] = ['start_month' => $start, 'end_month' => $end, 'crosses_year' => $wraps, 'months' => $group, 'peak_months' => $peaks,
                'indices' => $indices, 'usual_intensity' => $this->median(array_values($indices)), 'years_present' => $present, 'years_absent' => $absent,
                'years_unassessed' => $unassessed, 'quality' => $quality, 'statistically_confirmed' => false];
        }
        return $result;
    }
    /**
     * Divide exactamente con doce decimales reproducibles.
     * @param string $a Numerador.
     * @param string $b Denominador positivo.
     * @return string Cociente HALF_UP.
     */
    private function divide(string $a, string $b): string { return (string) BigDecimal::of($a)->dividedBy($b, 12, RoundingMode::HALF_UP); }
    /**
     * Calcula mediana sin eliminar extremos.
     * @param array $values Decimales.
     * @return string|null Centro robusto o desconocido.
     */
    private function median(array $values): ?string { if ($values === []) { return null; } usort($values, fn (string $a, string $b): int => BigDecimal::of($a)->compareTo($b)); $n = count($values); return $n % 2 ? $values[intdiv($n, 2)] : $this->divide((string) BigDecimal::of($values[intdiv($n, 2) - 1])->plus($values[intdiv($n, 2)]), '2'); }
    /**
     * Obtiene el máximo decimal.
     * @param array $values Valores no vacíos.
     * @return BigDecimal Máximo.
     */
    private function maximum(array $values): BigDecimal { return BigDecimal::max(...array_values($values)); }
    /**
     * Obtiene el mínimo decimal.
     * @param array $values Valores no vacíos.
     * @return BigDecimal Mínimo.
     */
    private function minimum(array $values): BigDecimal { return BigDecimal::min(...array_values($values)); }
    /**
     * Calcula promedio de magnitudes originales.
     * @param array $values Cantidades.
     * @return string Promedio exacto redondeado.
     */
    private function average(array $values): string { $sum = BigDecimal::zero(); foreach ($values as $value) { $sum = $sum->plus($value); } return $this->divide((string) $sum, (string) count($values)); }
    /**
     * Calcula cola binomial unilateral de signos; empates cuentan conservadoramente contra incremento.
     * @param int $n Años.
     * @param int $k Años por encima de uno.
     * @return string Valor p exploratorio, no confianza calibrada.
     */
    private function signTail(int $n, int $k): string { $sum = 0; $choose = 1; for ($i = 0; $i <= $n; $i++) { if ($i >= $k) { $sum += $choose; } if ($i < $n) { $choose = intdiv($choose * ($n - $i), $i + 1); } } return $this->divide((string) $sum, (string) (2 ** $n)); }
}
