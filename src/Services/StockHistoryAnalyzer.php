<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Contracts\InventoryMovementSource;
use Asisnet\Replenishment\Casts\ExactDecimal;
use Asisnet\Replenishment\DTO\InventoryMovementData;
use Asisnet\Replenishment\DTO\InventoryCoverageData;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\StockObservationData;
use Asisnet\Replenishment\DTO\StockIntervalData;
use Asisnet\Replenishment\DTO\StockHistoryData;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Evalúa puntos e intervalos físicos sin inferir reservas históricas, agotamientos a partir de ceros ni demanda perdida. */
final class StockHistoryAnalyzer
{
    /** @var string Versión de las condiciones explícitas de continuidad. */
    public const VERSION = 'physical-history-1.0';
    /** @var array<string> Inconsistencias que impiden atravesar un extremo. */
    private const AMBIGUOUS = ['stock_delta_mismatch', 'same_day_order_ambiguous', 'source_order_conflict', 'header_date_mismatch', 'ambiguous_header', 'unknown_document_status', 'unit_changed', 'equal_time_order_ambiguous'];
    /** @var array<string> Ausencias que impiden interpretar completamente una observación. */
    private const MISSING = ['invalid_observation_quantity', 'resulting_stock_missing', 'base_unit_unverified', 'physical_effect_unknown', 'sequence_validation_unverified', 'time_precision_unverified'];

    /**
     * Recibe servicios ya aprobados sin conexiones de base de datos.
     * @param HistoricalStockInspector $inspector Contraste consecutivo existente.
     * @param ObservedDemandCalculator $calendar Validador de calendario compartido.
     */
    public function __construct(private HistoricalStockInspector $inspector, private ObservedDemandCalculator $calendar) {}

    /**
     * Inspecciona una vez el flujo y verifica unicidad antes de publicar el diagnóstico.
     * @param InventoryMovementSource $source Fuente normalizada.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio inclusivo diario.
     * @param DateTimeImmutable $until Fin exclusivo diario.
     * @param string $timezone Zona operativa.
     * @param ObservedDemandData|null $sales Serie comercial del mismo alcance, sin modificarla.
     * @param InventoryCoverageData|null $coverage Atestación externa de cobertura comprobada; nunca inferida del éxito de lectura.
     * @return StockHistoryData Diagnóstico final; ante fallo no publica conteos parciales como totales.
     * @throws InvalidArgumentException Si los alcances o intervalos son incompatibles.
     */
    public function analyze(InventoryMovementSource $source, string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until,
        string $timezone, ?ObservedDemandData $sales = null, ?InventoryCoverageData $coverage = null): StockHistoryData
    {
        [$from, $until] = $this->calendar->bounds($from, $until, $timezone);
        if ($productId === '' || $locationId === '' || ($sales !== null && ($sales->productId !== $productId || $sales->locationId !== $locationId
            || $sales->from != $from || $sales->until != $until || $sales->timezone !== $timezone))
            || ($coverage !== null && ($coverage->productId !== $productId || $coverage->locationId !== $locationId))) {
            throw new InvalidArgumentException('El inventario, las ventas y la cobertura deben corresponder al mismo producto, ubicación y calendario solicitado.');
        }
        $days = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $days[$date->format('Y-m-d')] = ['observations' => 0, 'positive_observations' => 0, 'nonpositive_observations' => 0,
                'physical_state' => null, 'quality' => 'incomplete', 'covered_micros' => '0', 'positive_micros' => '0', 'nonpositive_micros' => '0',
                'issues' => [], 'sales_flags' => []];
        }
        $count = 0; $positive = 0; $nonpositive = 0; $unknown = 0; $discrepancies = 0; $inflow = 0; $outflow = 0;
        $observationQuality = array_fill_keys(['consistent', 'ambiguous', 'incomplete', 'unverified'], 0);
        $intervalQuality = $observationQuality;
        $observationCauses = []; $intervalCauses = []; $types = [];
        $observations = []; $intervals = []; $compatible = 0; $confirmedIntervals = 0;
        $previous = null; $failure = null; $identities = new EventIdentityIndex();
        try {
            foreach ($source->between($productId, $locationId, $from, $until) as $movement) {
                if (!$movement instanceof InventoryMovementData || $movement->productId !== $productId || $movement->locationId !== $locationId || $movement->id === '') {
                    throw new RuntimeException('scope_or_identity_mismatch');
                }
                if ($movement->occurredAt < $from || $movement->occurredAt >= $until) {
                    throw new RuntimeException('event_outside_interval');
                }
                if ($previous !== null && $movement->occurredAt < $previous->movement->occurredAt) {
                    throw new RuntimeException('chronological_order_invalid');
                }
                $identities->add($movement->id);
                if ($previous !== null && $previous->movement->id === $movement->id) {
                    throw new RuntimeException('duplicate_identity');
                }
                $observation = $this->observation($movement, $previous, $timezone);
                $key = $movement->occurredAt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
                $days[$key]['observations']++;
                $days[$key]['issues'] = array_values(array_unique(array_merge($days[$key]['issues'], $observation->issues)));
                $count++; $observationQuality[$observation->quality]++;
                $types[$movement->transactionType] = ($types[$movement->transactionType] ?? 0) + 1;
                if ($observation->observedState === 'positive') { $positive++; $days[$key]['positive_observations']++; }
                elseif ($observation->observedState === 'nonpositive') { $nonpositive++; $days[$key]['nonpositive_observations']++; }
                else { $unknown++; }
                if ($movement->inventoryDelta !== null) {
                    try { $delta = BigDecimal::of(ExactDecimal::normalize($movement->inventoryDelta)); $inflow += (int) $delta->isPositive(); $outflow += (int) $delta->isNegative(); }
                    catch (\Throwable $error) { /* La observación conserva la causa; no se inventa un efecto físico. */ }
                }
                $discrepancies += (int) in_array('stock_delta_mismatch', $observation->issues, true);
                $this->countIssues($observationCauses, $observation->issues);
                if (count($observations) < 50) { $observations[] = $observation; }
                if ($previous === null) {
                    if ($movement->occurredAt > $from) {
                        $this->recordInterval(new StockIntervalData($from, $movement->occurredAt, 'incomplete', null, false, ['initial_observation_missing']), $intervalQuality, $intervalCauses, $intervals, $compatible, $confirmedIntervals, $days, $timezone);
                    }
                } else {
                    $this->recordInterval($this->interval($previous, $observation, $coverage), $intervalQuality, $intervalCauses, $intervals, $compatible, $confirmedIntervals, $days, $timezone);
                }
                $previous = $observation;
            }
            $identities->finish();
            $this->recordInterval(new StockIntervalData($previous?->movement->occurredAt ?? $from, $until, 'incomplete', null, false,
                $previous === null ? ['no_observations', 'initial_observation_missing', 'final_observation_missing'] : ['final_observation_missing']),
                $intervalQuality, $intervalCauses, $intervals, $compatible, $confirmedIntervals, $days, $timezone);
        } catch (\Throwable $error) {
            $failure = in_array($error->getMessage(), ['scope_or_identity_mismatch', 'event_outside_interval', 'duplicate_identity', 'chronological_order_invalid', 'identity_storage_failure'], true)
                ? $error->getMessage() : 'source_failure';
            $observations = []; $intervals = [];
        } finally { $identities->close(); }
        $observedDays = 0; $knownDays = 0; $stockoutDays = 0; $positiveDays = 0; $mixedDays = 0;
        $salesFlags = array_fill_keys(['sales_on_days_with_nonpositive_observations', 'sales_with_unverified_physical_continuity', 'no_sales_and_unknown_stock'], 0);
        $comparedDays = 0;
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d'); $day = &$days[$key];
            $duration = $this->micros($date->modify('+1 day'))->minus($this->micros($date));
            if ($failure !== null) {
                $day['observations'] = $day['positive_observations'] = $day['nonpositive_observations'] = null;
                $day['physical_state'] = null; $day['quality'] = 'incomplete'; $day['issues'] = [$failure];
            } else {
                $observedDays += (int) ($day['observations'] > 0);
                if (BigInteger::of($day['covered_micros'])->isEqualTo($duration)) {
                    $knownDays++;
                    $day['quality'] = 'consistent';
                    if (BigInteger::of($day['nonpositive_micros'])->isEqualTo($duration)) { $day['physical_state'] = 'nonpositive'; $stockoutDays++; }
                    elseif (BigInteger::of($day['positive_micros'])->isEqualTo($duration)) { $day['physical_state'] = 'positive'; $positiveDays++; }
                    else { $day['physical_state'] = 'mixed'; $mixedDays++; }
                } elseif (array_intersect(self::AMBIGUOUS, $day['issues']) !== []) { $day['quality'] = 'ambiguous'; }
                elseif (array_intersect(self::MISSING, $day['issues']) !== []) { $day['quality'] = 'incomplete'; }
                elseif ($day['observations'] > 0) { $day['quality'] = 'unverified'; }
            }
            if ($sales !== null && $failure === null && ($sales->days[$key]['observation_status'] ?? null) === 'complete') {
                $comparedDays++;
                $hasSales = BigDecimal::of($sales->days[$key]['gross_sales_units'])->isPositive();
                if ($hasSales && $day['nonpositive_observations'] > 0) { $day['sales_flags'][] = 'sales_on_days_with_nonpositive_observations'; }
                if ($hasSales && $day['physical_state'] === null) { $day['sales_flags'][] = 'sales_with_unverified_physical_continuity'; }
                if (!$hasSales && $day['physical_state'] === null) { $day['sales_flags'][] = 'no_sales_and_unknown_stock'; }
                foreach ($day['sales_flags'] as $flag) { $salesFlags[$flag]++; }
            }
            unset($day['covered_micros'], $day['positive_micros'], $day['nonpositive_micros']);
            unset($day);
        }
        $calendarDays = count($days);
        $comparisonComplete = $sales !== null && $comparedDays === $calendarDays;
        $summary = ['reading_status' => $failure === null ? 'complete' : 'incomplete', 'failure_cause' => $failure,
            'calendar_days' => $calendarDays, 'days_with_inventory_observations' => $failure === null ? $observedDays : null,
            'days_with_sufficient_physical_evidence' => $failure === null ? $knownDays : null,
            'days_with_insufficient_physical_evidence' => $failure === null ? $calendarDays - $knownDays : $calendarDays,
            'observations' => $failure === null ? $count : null, 'positive_observations' => $failure === null ? $positive : null,
            'nonpositive_observations' => $failure === null ? $nonpositive : null, 'unknown_balance_observations' => $failure === null ? $unknown : null,
            'inflow_observations' => $failure === null ? $inflow : null, 'outflow_observations' => $failure === null ? $outflow : null,
            'continuity_discrepancies' => $failure === null ? $discrepancies : null, 'observation_quality_counts' => $failure === null ? $observationQuality : null,
            'interval_quality_counts' => $failure === null ? $intervalQuality : null, 'compatible_stockout_intervals' => $failure === null ? $compatible : null,
            'confirmed_intervals' => $failure === null ? $confirmedIntervals : null,
            'confirmed_stockout_days' => $failure === null && $knownDays > 0 ? $stockoutDays : null,
            'confirmed_positive_days' => $failure === null && $knownDays > 0 ? $positiveDays : null,
            'confirmed_mixed_days' => $failure === null && $knownDays > 0 ? $mixedDays : null,
            'total_stockout_days' => $failure === null && $knownDays === $calendarDays ? $stockoutDays : null,
            'historical_commercial_availability' => null, 'lost_demand_units' => null,
            'observation_causes' => $failure === null ? $observationCauses : [], 'interval_causes' => $failure === null ? $intervalCauses : [],
            'transaction_type_counts' => $failure === null ? $types : [], 'coverage_evidence_reference' => $coverage?->evidenceReference,
            'observation_samples_limit' => 50, 'interval_samples_limit' => 50,
            'sales_comparison' => ['status' => $sales === null ? 'not_requested' : ($comparisonComplete ? 'complete' : 'incomplete'),
                'compared_days' => $comparedDays, 'flags' => $comparisonComplete ? $salesFlags : array_fill_keys(array_keys($salesFlags), null),
                'same_day_does_not_prove_simultaneity' => true, 'cross_source_snapshot_verified' => false]];
        return new StockHistoryData($productId, $locationId, $from, $until, $timezone, $summary, $days, $observations, $intervals);
    }

    /**
     * Reutiliza el inspector consecutivo y conserva el saldo separado de su calidad.
     * @param InventoryMovementData $movement Movimiento original.
     * @param StockObservationData|null $previous Punto anterior.
     * @param string $timezone Zona del calendario de análisis.
     * @return StockObservationData Observación sin alterar el original.
     */
    private function observation(InventoryMovementData $movement, ?StockObservationData $previous, string $timezone): StockObservationData
    {
        $issues = $movement->issues;
        $balance = null; $validNumbers = true;
        try {
            $balance = $movement->resultingStock === null ? null : ExactDecimal::normalize($movement->resultingStock);
            if ($movement->inventoryDelta !== null) { ExactDecimal::normalize($movement->inventoryDelta); }
        } catch (\Throwable $error) { $issues[] = 'invalid_observation_quantity'; $validNumbers = false; }
        if ($validNumbers && ($previous === null || $previous->observedBalance !== null)) {
            try {
                $sequence = $previous === null ? [$movement] : [$previous->movement, $movement];
                foreach ($this->inspector->inspect($sequence) as $checked) {
                    if ($checked->id === $movement->id) { $issues = array_merge($issues, $checked->issues); }
                }
            } catch (\Throwable $error) { $issues[] = 'sequence_validation_unverified'; }
        } else { $issues[] = 'opening_observation_missing'; }
        if ($balance === null) { $issues[] = 'resulting_stock_missing'; }
        if ($movement->baseUnit === null || trim($movement->baseUnit) === '') { $issues[] = 'base_unit_unverified'; }
        if ($movement->inventoryDelta === null) { $issues[] = 'physical_effect_unknown'; }
        if (!in_array($movement->timePrecision, ['day', 'instant'], true)) { $issues[] = 'time_precision_unverified'; }
        if ($previous !== null && $previous->movement->occurredAt == $movement->occurredAt) { $issues[] = 'equal_time_order_ambiguous'; }
        if ($previous !== null && ($movement->timePrecision === 'day' || $previous->movement->timePrecision === 'day')
            && $movement->occurredAt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d') === $previous->movement->occurredAt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d')) {
            $issues[] = 'same_day_order_ambiguous';
        }
        $issues = array_values(array_unique($issues));
        $incomplete = array_intersect(self::MISSING, $issues) !== [] || in_array('opening_observation_missing', $issues, true);
        $quality = array_intersect(self::AMBIGUOUS, $issues) !== [] ? 'ambiguous' : ($incomplete ? 'incomplete' : 'consistent');
        if ($quality === 'consistent' && in_array('sequence_validation_unverified', $issues, true)) { $quality = 'unverified'; }
        return new StockObservationData($movement, $balance, $balance === null ? null : (BigDecimal::of($balance)->isPositive() ? 'positive' : 'nonpositive'), $quality, $issues);
    }

    /**
     * Evalúa el tramo posterior al primer saldo y anterior al movimiento siguiente.
     * @param StockObservationData $previous Extremo inicial.
     * @param StockObservationData $current Extremo final.
     * @param InventoryCoverageData|null $coverage Evidencia externa de todos los cambios físicos.
     * @return StockIntervalData Compatibilidad o continuidad comprobada según los requisitos disponibles.
     */
    private function interval(StockObservationData $previous, StockObservationData $current, ?InventoryCoverageData $coverage): StockIntervalData
    {
        $a = $previous->movement; $b = $current->movement;
        $issues = array_values(array_unique(array_merge($previous->issues, $current->issues)));
        // El saldo inicial sirve como anclaje hacia adelante; no demuestra nada antes de ese punto.
        $issues = array_values(array_diff($issues, ['opening_observation_missing']));
        $complete = array_intersect(self::MISSING, $issues) === [] && $previous->observedBalance !== null && $current->observedBalance !== null && $a->baseUnit !== null
            && trim($a->baseUnit) !== '' && $a->baseUnit === $b->baseUnit && $a->inventoryDelta !== null && $b->inventoryDelta !== null;
        if (!$complete) { $issues[] = 'interval_data_missing_or_units_incompatible'; }
        $ambiguous = array_intersect(self::AMBIGUOUS, $issues) !== [] || $a->occurredAt == $b->occurredAt;
        $blocked = array_diff($issues, ['time_of_day_unknown']);
        $candidate = $complete && !$ambiguous && $blocked === [] ? $previous->observedState : null;
        $instant = $a->timePrecision === 'instant' && $b->timePrecision === 'instant' && !in_array('time_of_day_unknown', $issues, true);
        if (!$instant) { $issues[] = 'interval_time_not_verified'; }
        $covered = $coverage !== null && $coverage->from <= $a->occurredAt && $coverage->until >= $b->occurredAt && $coverage->baseUnit === $a->baseUnit;
        if (!$covered) { $issues[] = 'documentary_coverage_unverified'; }
        $confirmed = $candidate !== null && $instant && $covered && $a->occurredAt < $b->occurredAt;
        $quality = $ambiguous ? 'ambiguous' : (!$complete ? 'incomplete' : ($confirmed ? 'consistent' : 'unverified'));
        return new StockIntervalData($a->occurredAt, $b->occurredAt, $quality, $candidate, $confirmed, array_values(array_unique($issues)), $confirmed ? $coverage->evidenceReference : null);
    }

    /**
     * Acumula conteos y sólo acredita duración cuando todos los requisitos del tramo están demostrados.
     * @param StockIntervalData $interval Tramo.
     * @param array $quality Conteos de calidad por referencia.
     * @param array $causes Causas por referencia.
     * @param array $samples Muestras limitadas.
     * @param int $compatible Conteo potencial, no duración.
     * @param int $confirmed Conteo de tramos confirmados.
     * @param array $days Acumuladores diarios exactos.
     * @param string $timezone Zona civil.
     * @return void
     */
    private function recordInterval(StockIntervalData $interval, array &$quality, array &$causes, array &$samples, int &$compatible, int &$confirmed, array &$days, string $timezone): void
    {
        $quality[$interval->quality]++; $this->countIssues($causes, $interval->issues);
        if (count($samples) < 50) { $samples[] = $interval; }
        $compatible += (int) ($interval->candidateState === 'nonpositive' && $interval->from < $interval->until);
        if (!$interval->confirmed) { return; }
        $confirmed++;
        $cursor = $interval->from->setTimezone(new DateTimeZone($timezone));
        while ($cursor < $interval->until) {
            $boundary = $cursor->setTime(0, 0)->modify('+1 day');
            $end = $boundary < $interval->until ? $boundary : $interval->until;
            $key = $cursor->format('Y-m-d');
            $duration = $this->micros($end)->minus($this->micros($cursor));
            $days[$key]['covered_micros'] = (string) BigInteger::of($days[$key]['covered_micros'])->plus($duration);
            $field = $interval->candidateState === 'positive' ? 'positive_micros' : 'nonpositive_micros';
            $days[$key][$field] = (string) BigInteger::of($days[$key][$field])->plus($duration);
            $cursor = $end->setTimezone(new DateTimeZone($timezone));
        }
    }

    /**
     * Cuenta causas sin repetir la misma causa dentro de una observación o tramo.
     * @param array $counts Conteos.
     * @param array<string> $issues Causas.
     * @return void
     */
    private function countIssues(array &$counts, array $issues): void
    {
        foreach (array_unique($issues) as $issue) { $counts[$issue] = ($counts[$issue] ?? 0) + 1; }
    }

    /**
     * Representa un instante en microsegundos exactos sin float.
     * @param DateTimeImmutable $date Instante.
     * @return BigInteger Microsegundos desde época.
     */
    private function micros(DateTimeImmutable $date): BigInteger
    {
        return BigInteger::of($date->format('U'))->multipliedBy(1000000)->plus($date->format('u'));
    }
}
