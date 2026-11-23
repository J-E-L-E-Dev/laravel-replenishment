<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Contracts\InventoryMovementSource;
use Asisnet\Replenishment\DTO\InventoryMovementData;
use Asisnet\Replenishment\DTO\InventoryCoverageData;
use Asisnet\Replenishment\Services\StockHistoryAnalyzer;
use Asisnet\Replenishment\Services\HistoricalStockInspector;
use Asisnet\Replenishment\Services\ObservedDemandCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Fuente sintética diferida para probar secuencias sin bases reales. */
final class StockHistoryFixture implements InventoryMovementSource
{
    /**
     * Recibe datos ficticios y fallo opcional.
     * @param iterable $rows Movimientos.
     * @param bool $fail Fallo tardío.
     */
    public function __construct(private iterable $rows, private bool $fail = false) {}
    /**
     * Devuelve el flujo tal cual para detectar violaciones de contrato.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Corte.
     * @return iterable Movimientos.
     * @throws RuntimeException Si se solicita fallo.
     */
    public function between(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until): iterable
    {
        yield from $this->rows;
        if ($this->fail) { throw new RuntimeException('Fallo de prueba.'); }
    }
}

/** Verifica puntos, continuidad condicionada a evidencia, días desconocidos y separación comercial. */
final class StockHistoryTest extends TestCase
{
    /**
     * Construye un instante operativo.
     * @param string $value Fecha y hora.
     * @return DateTimeImmutable Instante.
     */
    private function date(string $value): DateTimeImmutable { return new DateTimeImmutable($value, new \DateTimeZone('America/Caracas')); }
    /**
     * Construye un movimiento genérico.
     * @param string $id Identidad.
     * @param string $date Fecha.
     * @param string|null $stock Saldo.
     * @param string|null $delta Efecto.
     * @param string $precision Precisión.
     * @param string|null $unit Unidad.
     * @param string $product Producto.
     * @param string $location Ubicación.
     * @return InventoryMovementData Original.
     */
    private function row(string $id, string $date, ?string $stock, ?string $delta = '0', string $precision = 'day', ?string $unit = 'U', string $product = 'P', string $location = 'L'): InventoryMovementData
    {
        return new InventoryMovementData($id, $product, $location, 'D', 'N', 'physical_change', $this->date($date), $delta, $delta, $delta, null, $unit, $unit, $stock, $precision, [], [], $id);
    }
    /**
     * Crea el motor sin base de datos.
     * @return StockHistoryAnalyzer Servicio.
     */
    private function analyzer(): StockHistoryAnalyzer { return new StockHistoryAnalyzer(new HistoricalStockInspector(), new ObservedDemandCalculator()); }
    /**
     * Ejecuta una ventana sintética de tres días.
     * @param iterable $rows Movimientos.
     * @param InventoryCoverageData|null $coverage Cobertura demostrada en el fixture.
     * @param bool $fail Fallo.
     * @return \Asisnet\Replenishment\DTO\StockHistoryData Diagnóstico.
     */
    private function runHistory(iterable $rows, ?InventoryCoverageData $coverage = null, bool $fail = false)
    {
        return $this->analyzer()->analyze(new StockHistoryFixture($rows, $fail), 'P', 'L', $this->date('2024-01-01'), $this->date('2024-01-04'), 'America/Caracas', null, $coverage);
    }
    /**
     * Declara cobertura íntegra conocida sólo para datos sintéticos.
     * @return InventoryCoverageData Evidencia ficticia identificada.
     */
    private function coverage(): InventoryCoverageData
    {
        return new InventoryCoverageData('P', 'L', $this->date('2024-01-01'), $this->date('2024-01-04'), 'U', 'fixture-complete-ledger');
    }

    /**
     * Saldos diarios coherentes no equivalen a continuidad ni disponibilidad comercial.
     * @return void
     */
    public function testPositiveConsistentObservationsRemainUnverifiedIntervals(): void
    {
        $first = $this->row('1', '2024-01-01', '10');
        $result = $this->runHistory([$first, $this->row('2', '2024-01-02', '12', '2')]);
        self::assertSame(2, $result->summary['positive_observations']);
        self::assertSame(0, $result->summary['continuity_discrepancies']);
        self::assertSame('consistent', $result->observationSamples[1]->quality);
        self::assertSame('unverified', $result->intervalSamples[0]->quality);
        self::assertNull($result->summary['confirmed_stockout_days']);
        self::assertNull($result->summary['historical_commercial_availability']);
        self::assertSame([], $first->issues);
    }

    /**
     * Un cero o saldo negativo observado no certifica un día agotado.
     * @return void
     */
    public function testZeroNegativeAndPotentialIntervals(): void
    {
        $result = $this->runHistory([$this->row('1', '2024-01-01', '0'), $this->row('2', '2024-01-02', '-1', '-1')]);
        self::assertSame(2, $result->summary['nonpositive_observations']);
        self::assertSame(1, $result->summary['compatible_stockout_intervals']);
        self::assertSame(0, $result->summary['days_with_sufficient_physical_evidence']);
        self::assertNull($result->summary['confirmed_stockout_days']);
        self::assertNull($result->days['2024-01-01']['physical_state']);
    }

    /**
     * Sólo horas fiables y cobertura íntegra permiten acreditar días entre anclajes; nunca después del último.
     * @return void
     */
    public function testConfirmedSubsetRequiresExplicitCoverageAndReliableTimes(): void
    {
        $rows = [$this->row('1', '2024-01-01', '10', '0', 'instant'), $this->row('2', '2024-01-02', '0', '-10', 'instant'), $this->row('3', '2024-01-03', '2', '2', 'instant')];
        self::assertNull($this->runHistory($rows)->summary['confirmed_stockout_days']);
        $result = $this->runHistory($rows, $this->coverage());
        self::assertSame(2, $result->summary['days_with_sufficient_physical_evidence']);
        self::assertSame(1, $result->summary['confirmed_stockout_days']);
        self::assertSame(1, $result->summary['confirmed_positive_days']);
        self::assertSame('nonpositive', $result->days['2024-01-02']['physical_state']);
        self::assertNull($result->days['2024-01-03']['physical_state']);
        self::assertNull($result->summary['total_stockout_days']);
    }

    /**
     * Una cobertura de otra unidad o incompleta no acredita duración; un día mixto no cuenta como agotado.
     * @return void
     */
    public function testCoverageBoundariesUnitsAndMixedDay(): void
    {
        $rows = [$this->row('1', '2024-01-01', '0', '0', 'instant'), $this->row('2', '2024-01-01 12:00:00', '1', '1', 'instant'), $this->row('3', '2024-01-02', '1', '0', 'instant')];
        $mixed = $this->runHistory($rows, $this->coverage());
        self::assertSame('mixed', $mixed->days['2024-01-01']['physical_state']);
        self::assertSame(0, $mixed->summary['confirmed_stockout_days']);
        foreach ([['OTHER', '2024-01-01'], ['U', '2024-01-02']] as [$unit, $from]) {
            $coverage = new InventoryCoverageData('P', 'L', $this->date($from), $this->date('2024-01-04'), $unit, 'fixture-limited');
            self::assertSame(0, $this->runHistory($rows, $coverage)->summary['confirmed_intervals']);
        }
    }

    /**
     * Un intervalo de día parcial nunca se redondea a un día completo.
     * @return void
     */
    public function testMicrosecondsDoNotCreateFullDayCoverage(): void
    {
        $result = $this->runHistory([$this->row('1', '2024-01-01 00:00:00.000001', '0', '0', 'instant'), $this->row('2', '2024-01-02', '0', '0', 'instant')], $this->coverage());
        self::assertSame(1, $result->summary['confirmed_intervals']);
        self::assertSame(0, $result->summary['days_with_sufficient_physical_evidence']);
        self::assertNull($result->summary['confirmed_stockout_days']);
    }

    /**
     * Operaciones simultáneas sin orden demostrado impiden atravesar el tramo aun con cobertura documental.
     * @return void
     */
    public function testSameDayUnknownTimeAndEqualInstants(): void
    {
        foreach (['day', 'instant'] as $precision) {
            $result = $this->runHistory([$this->row('1', '2024-01-01', '0', '0', $precision), $this->row('2', '2024-01-01', '2', '2', $precision), $this->row('3', '2024-01-02', '3', '1', $precision)], $this->coverage());
            self::assertSame(0, $result->summary['confirmed_intervals']);
            self::assertGreaterThan(0, $result->summary['interval_quality_counts']['ambiguous']);
            self::assertNull($result->summary['confirmed_stockout_days']);
        }
    }

    /**
     * Una diferencia de saldos no se corrige para obtener continuidad.
     * @return void
     */
    public function testBalanceMismatchAndSignedAdjustments(): void
    {
        $rows = [$this->row('1', '2024-01-01', '10'), $this->row('2', '2024-01-02', '12.125', '2.125'), $this->row('3', '2024-01-03', '11', '-1.125')];
        $good = $this->runHistory($rows);
        self::assertSame(0, $good->summary['continuity_discrepancies']);
        self::assertSame(1, $good->summary['inflow_observations']);
        self::assertSame(1, $good->summary['outflow_observations']);
        $badRow = $this->row('2', '2024-01-02', '999', '-1');
        $bad = $this->runHistory([$rows[0], $badRow], $this->coverage());
        self::assertSame(1, $bad->summary['continuity_discrepancies']);
        self::assertSame('999', $badRow->resultingStock);
        self::assertSame(0, $bad->summary['confirmed_intervals']);
    }

    /**
     * Cambios de unidad y efectos desconocidos impiden reconstruir cantidades.
     * @return void
     */
    public function testUnitsUnknownBalancesAndUnknownMovement(): void
    {
        $result = $this->runHistory([$this->row('1', '2024-01-01', '1'), $this->row('2', '2024-01-02', '1', null, 'day', 'OTHER'), $this->row('3', '2024-01-03', null, null, 'day', null)]);
        self::assertSame(1, $result->summary['unknown_balance_observations']);
        self::assertArrayHasKey('unit_changed', $result->summary['observation_causes']);
        self::assertArrayHasKey('physical_effect_unknown', $result->summary['observation_causes']);
        self::assertSame(0, $result->summary['confirmed_intervals']);
    }

    /**
     * La falta de anclajes y los días vacíos conservan la existencia desconocida.
     * @return void
     */
    public function testNoObservationsAndBoundaryGaps(): void
    {
        $empty = $this->runHistory([]);
        self::assertSame(0, $empty->summary['days_with_inventory_observations']);
        self::assertSame(3, $empty->summary['days_with_insufficient_physical_evidence']);
        self::assertNull($empty->summary['confirmed_stockout_days']);
        $middle = $this->runHistory([$this->row('1', '2024-01-02', '0')]);
        self::assertArrayHasKey('initial_observation_missing', $middle->summary['interval_causes']);
        self::assertArrayHasKey('final_observation_missing', $middle->summary['interval_causes']);
        self::assertNull($middle->days['2024-01-01']['physical_state']);
    }

    /**
     * Orden inválido, duplicados y fallo tardío invalidan totales y muestras, no publican conteos parciales.
     * @return void
     */
    public function testInvalidOrderDuplicatesAndSourceFailure(): void
    {
        $cases = [
            [[$this->row('1', '2024-01-02', '0'), $this->row('2', '2024-01-01', '0')], false, 'chronological_order_invalid'],
            [[$this->row('1', '2024-01-01', '0'), $this->row('2', '2024-01-02', '0'), $this->row('1', '2024-01-03', '0')], false, 'duplicate_identity'],
            [[$this->row('1', '2024-01-01', '0')], true, 'source_failure'],
        ];
        foreach ($cases as [$rows, $fail, $cause]) {
            $result = $this->runHistory($rows, null, $fail);
            self::assertSame($cause, $result->summary['failure_cause']);
            self::assertNull($result->summary['observations']);
            self::assertSame([], $result->observationSamples);
            self::assertNull($result->days['2024-01-01']['nonpositive_observations']);
        }
    }

    /**
     * La identidad numérica regresiva indica orden documental dudoso, no una hora reconstruida.
     * @return void
     */
    public function testPossibleRetroactiveOrder(): void
    {
        $result = $this->runHistory([$this->row('2', '2024-01-01', '1'), $this->row('1', '2024-01-02', '2', '1')]);
        self::assertArrayHasKey('source_order_conflict', $result->summary['observation_causes']);
        self::assertSame(0, $result->summary['confirmed_intervals']);
    }

    /**
     * Se rechazan alcances mezclados y los muestras se mantienen acotadas.
     * @return void
     */
    public function testScopeIsolationAndBoundedSamples(): void
    {
        foreach ([['OTHER', 'L'], ['P', 'OTHER']] as [$product, $location]) {
            self::assertSame('scope_or_identity_mismatch', $this->runHistory([$this->row('1', '2024-01-01', '1', '0', 'day', 'U', $product, $location)])->summary['failure_cause']);
        }
        $rows = (function (): iterable { for ($id = 1; $id <= 120; $id++) { yield $this->row((string) $id, '2024-01-01', '0'); } })();
        $result = $this->runHistory($rows);
        self::assertSame(120, $result->summary['observations']);
        self::assertCount(50, $result->observationSamples);
        self::assertCount(50, $result->intervalSamples);
    }

    /**
     * El cruce comercial es diagnóstico diario, no corrección de ventas ni prueba de simultaneidad.
     * @return void
     */
    public function testSalesComparisonDoesNotModifySales(): void
    {
        $days = [];
        foreach (['2024-01-01', '2024-01-02', '2024-01-03'] as $date) {
            $value = $date === '2024-01-01' ? '2.000000' : '0.000000';
            $days[$date] = ['gross_sales_units' => $value, 'returned_units' => '0.000000', 'reversed_return_units' => '0.000000', 'net_sales_units' => $value,
                'event_count' => $date === '2024-01-01' ? 1 : 0, 'base_unit' => 'U', 'observation_status' => 'complete', 'quality_issues' => [], 'inventory_quality_issues' => []];
        }
        $sales = (new ObservedDemandCalculator())->fromDays('P', 'L', $this->date('2024-01-01'), $this->date('2024-01-04'), 'America/Caracas', $days);
        $result = $this->analyzer()->analyze(new StockHistoryFixture([$this->row('1', '2024-01-01', '0')]), 'P', 'L', $sales->from, $sales->until, 'America/Caracas', $sales);
        self::assertSame('2.000000', $sales->summary['net_sales_units']);
        self::assertSame(1, $result->summary['sales_comparison']['flags']['sales_on_days_with_nonpositive_observations']);
        self::assertSame(2, $result->summary['sales_comparison']['flags']['no_sales_and_unknown_stock']);
        self::assertTrue($result->summary['sales_comparison']['same_day_does_not_prove_simultaneity']);
        self::assertNull($result->summary['lost_demand_units']);
    }
}
