<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\CommercialSalesData;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Construye desgloses reconciliables y una proyección independiente en la unidad requerida. */
final class CommercialSalesReport
{
    /** @param ObservedDemandCalculator $calculator Consolidador de la serie homogénea. */
    public function __construct(private ObservedDemandCalculator $calculator) {}

    /** Devuelve el calendario completo y magnitudes por unidad. @param CommercialSalesData $data Ejecución única. @return array Informe serializable. */
    public function breakdown(CommercialSalesData $data): array
    {
        $groups = ['weeks' => [], 'months' => [], 'years' => []];
        foreach ($data->days as $date => $day) {
            $at = new DateTimeImmutable($date, $data->from->getTimezone());
            $month = $at->modify('first day of this month');
            $week = max($month, $at->modify('monday this week'));
            $nextMonth = $month->modify('+1 month');
            $year = $at->setDate((int) $at->format('Y'), 1, 1);
            foreach (['weeks' => [$week, min($nextMonth, $at->modify('monday next week'))],
                'months' => [$month, $nextMonth], 'years' => [$year, $year->modify('+1 year')]] as $level => [$start, $end]) {
                $key = $start->format(['weeks'=>'Y-m-d','months'=>'Y-m','years'=>'Y'][$level]);
                $groups[$level][$key]['days'][$date] = $day;
                if ($level === 'weeks') {
                    $groups[$level][$key]['clipped_week'] = $start->diff($end)->days < 7;
                }
                $groups[$level][$key]['partial_calendar_period'] = $start < $data->from || $end > $data->until;
            }
        }
        foreach ($groups as &$periods) {
            foreach ($periods as &$period) {
                $days = $period['days'];
                unset($period['days']);
                $period += $this->period($days, $data->profile['current_unit']);
            }
            unset($period);
        }
        unset($periods);
        return $this->evidence($data) + ['status' => $data->status, 'days' => $data->days,
            'summary' => $this->period($data->days, $data->profile['current_unit'])] + $groups;
    }

    /** Suma sólo dentro de cada unidad y separa cantidades parciales. @param array $days Observaciones.
     * @param string|null $required Unidad actual para comparar. @return array Cobertura y componentes exactos por unidad. */
    private function period(array $days, ?string $required): array
    {
        $states = array_count_values(array_column($days, 'state'));
        $units = [];
        $issues = [];
        $events = 0;
        $incompatible = 0;
        foreach ($days as $day) {
            $events += $day['event_count'] ?? 0;
            $issues = array_values(array_unique([...$issues, ...$day['issues']]));
            if ($day['units'] && (count($day['units']) !== 1 || !isset($day['units'][$required]))) {
                $incompatible++;
            }
            foreach ($day['units'] as $key => $bucket) {
                if (!isset($units[$key])) {
                    $units[$key] = $bucket;
                    continue;
                }
                foreach (ObservedDemandCalculator::QUANTITIES as $field) {
                    $units[$key][$field] = (string) BigDecimal::of($units[$key][$field])->plus($bucket[$field]);
                }
                $units[$key]['event_count'] += $bucket['event_count'];
            }
        }
        $outside = $states['outside_cutoff'] ?? 0;
        $observed = ($states['complete'] ?? 0) + ($states['zero_observed'] ?? 0);
        $complete = $observed > 0 && $observed + $outside === count($days);
        return ['from' => array_key_first($days), 'last_day' => array_key_last($days), 'requested_days' => count($days),
            'effective_days' => count($days) - $outside, 'observed_days' => $observed, 'outside_cutoff_days' => $outside,
            'unknown_days' => $states['unknown'] ?? 0, 'incomplete_days' => $states['incomplete'] ?? 0,
            'zero_days' => $states['zero_observed'] ?? 0, 'incompatible_days' => $incompatible,
            'multiple_units' => count($units) > 1, 'issues' => $issues,
            'effective_coverage_complete' => $complete, 'event_count' => $complete ? $events : null,
            'totals_by_unit' => $complete ? array_values($units) : null, 'observed_by_unit' => array_values($units)];
    }

    /** Documenta versión, alcance y procedencia sin consultar maestros. @param CommercialSalesData $data Ejecución. @return array Evidencia común. */
    private function evidence(CommercialSalesData $data): array
    {
        return ['profile' => $data->profile, 'profile_hash' => CommercialProfile::hash($data->profile),
            'run_id' => $data->runId, 'origin' => $data->origin, 'requested_start' => $data->from->format('Y-m-d'),
            'effective_start' => $data->effective->format('Y-m-d'), 'until' => $data->until->format('Y-m-d'),
            'calculated_at' => $data->calculatedAt->format(DATE_ATOM), 'limitation' => $data->limitation];
    }

    /** Proyecta exclusivamente días con evidencia comparable. El cero sin unidad conserva su cobertura histórica.
     * @param CommercialSalesData $data Ejecución histórica. @return ObservedDemandData Serie para motores de una sola unidad. */
    public function project(CommercialSalesData $data): ObservedDemandData
    {
        $unit = $data->profile['current_unit'];
        $days = [];
        foreach ($data->days as $date => $day) {
            $compatible = $day['state'] === 'complete' && count($day['units']) === 1 && isset($day['units'][$unit]);
            $bucket = $compatible ? $day['units'][$unit] : array_fill_keys(ObservedDemandCalculator::QUANTITIES, null);
            $reason = match ($day['state']) {
                'zero_observed' => 'historical_reference_unit_unknown', 'complete' => 'incompatible_unit', default => $day['state']
            };
            $days[$date] = $bucket + ['event_count' => null, 'observation_status' => $compatible ? 'complete' : 'incomplete',
                'base_unit' => $compatible ? $unit : null, 'quality_issues' => $compatible ? $day['issues'] : array_unique([...$day['issues'], $reason]),
                'inventory_quality_issues' => []];
            if ($data->origin === 'cache') {
                $days[$date]['cached_calculated_at'] = $data->calculatedAt->format(DATE_ATOM);
            }
        }
        $coverage = $this->period($data->days, $unit);
        $coverage['observed_units'] = array_column($coverage['observed_by_unit'], 'unit');
        unset($coverage['totals_by_unit'], $coverage['observed_by_unit']);
        return $this->calculator->fromDays($data->profile['product'], $data->profile['location'], $data->from, $data->until,
            $data->profile['timezone'], $days, $data->origin, $data->profile['aggregator'],
            $this->evidence($data) + ['status' => $data->status, 'coverage' => $coverage], $data->calculatedAt);
    }
}
