<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Contracts\SaleSource;
use Asisnet\Replenishment\DTO\SaleData;
use Asisnet\Replenishment\DTO\InventoryMovementData;
use Asisnet\Replenishment\Services\ObservedDemandCalculator;
use Asisnet\Replenishment\Services\ObservedDemandQuery;
use Asisnet\Replenishment\Services\ObservedDemandStore;
use Asisnet\Replenishment\Services\PackageMigrator;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Asisnet\Replenishment\Models\DailySale;
use Asisnet\Replenishment\Models\ProductMetric;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Fuente sintética diferida sin datos comerciales reales. */
final class ObservedSalesFixture implements SaleSource
{
    /**
     * Recibe eventos ficticios y un fallo opcional al terminar.
     * @param iterable $events Eventos.
     * @param bool $fail Indica fallo tardío.
     */
    public function __construct(private iterable $events, private bool $fail = false) {}
    /**
     * Entrega eventos sin filtrar para probar también violaciones del contrato.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Corte.
     * @return iterable<SaleData> Eventos.
     * @throws RuntimeException Si se simula un fallo.
     */
    public function between(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until): iterable
    {
        yield from $this->events;
        if ($this->fail) { throw new RuntimeException('Fallo sintético después de emitir eventos.'); }
    }
}

/** Prueba calendario, aritmética, calidad, caché y métricas en conexiones aisladas. */
final class ObservedDemandTest extends TestCase
{
    /**
     * Registra servicios reales del paquete.
     * @param mixed $app Aplicación aislada.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array { return [ReplenishmentServiceProvider::class]; }
    /**
     * Declara dos SQLite sin credenciales del consumidor.
     * @param mixed $app Aplicación.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach (['own', 'erp'] as $name) {
            $app['config']->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
        $app['config']->set('database.default', 'erp');
        $app['config']->set('replenishment.connection', 'own');
        $app['config']->set('app.timezone', 'America/Caracas');
    }
    /**
     * Restablece el reloj de pruebas.
     * @return void
     */
    protected function tearDown(): void { CarbonImmutable::setTestNow(); parent::tearDown(); }
    /**
     * Construye una fecha operativa.
     * @param string $date Fecha civil.
     * @return DateTimeImmutable Medianoche.
     */
    private function date(string $date): DateTimeImmutable { return new DateTimeImmutable($date.'T00:00:00-04:00'); }
    /**
     * Construye un evento semántico ficticio.
     * @param string $id Identidad.
     * @param string $date Fecha.
     * @param string $quantity Cantidad firmada.
     * @param string|null $kind Tipo.
     * @param string|null $unit Unidad.
     * @return SaleData Evento.
     */
    private function sale(string $id, string $date, string $quantity, ?string $kind = 'sale', ?string $unit = 'U'): SaleData
    {
        return new SaleData($id, 'P', 'L', $quantity, $this->date($date), null, $kind, $unit);
    }
    /**
     * Calcula un período ficticio sin acceso a bases.
     * @param iterable $events Eventos.
     * @param string $from Inicio.
     * @param string $until Corte.
     * @param bool $fail Fallo tardío.
     * @return \Asisnet\Replenishment\DTO\ObservedDemandData Resultado.
     */
    private function calculate(iterable $events, string $from = '2024-01-01', string $until = '2025-01-01', bool $fail = false)
    {
        return (new ObservedDemandCalculator())->calculate(new ObservedSalesFixture($events, $fail), 'P', 'L', $this->date($from), $this->date($until), 'America/Caracas');
    }

    /**
     * Verifica bruto, devoluciones, reversiones y múltiples ventas del mismo día con decimales exactos.
     * @return void
     */
    public function testComponentsAndCalendarIndicators(): void
    {
        $result = $this->calculate([$this->sale('1', '2024-01-01', '2.125'), $this->sale('2', '2024-01-01', '1.125'), $this->sale('3', '2024-01-02', '-1.25', 'return'), $this->sale('4', '2024-01-03', '0.25', 'return_reversal')], '2024-01-01', '2024-02-01');
        self::assertSame('3.250000', $result->summary['gross_sales_units']);
        self::assertSame('1.250000', $result->summary['returned_units']);
        self::assertSame('0.250000', $result->summary['reversed_return_units']);
        self::assertSame('2.250000', $result->summary['net_sales_units']);
        self::assertSame(3, $result->summary['days_with_commercial_events']);
        self::assertSame(1, $result->summary['days_with_positive_sales']);
        self::assertSame(30, $result->summary['days_without_registered_sales']);
        self::assertSame(28, $result->summary['days_without_commercial_events']);
        self::assertSame('0.072581', $result->summary['average_daily_calendar_net']);
        self::assertSame('2.250000', $result->summary['average_net_per_sales_day']);
        self::assertSame('2.250000', $result->summary['average_monthly_net']);
        self::assertSame('0.032258', $result->summary['sales_frequency']);
        self::assertSame('0.000000', $result->days['2024-01-04']['net_sales_units']);
    }

    /**
     * Una devolución total o de otro período conserva cero o neto negativo sin reasignar fechas.
     * @return void
     */
    public function testTotalReturnAndNegativeNet(): void
    {
        $result = $this->calculate([$this->sale('1', '2024-01-31', '1'), $this->sale('2', '2024-02-01', '-1', 'return'), $this->sale('3', '2024-02-02', '-0.25', 'return')]);
        self::assertSame('-0.250000', $result->summary['net_sales_units']);
        self::assertSame('1.000000', $result->months['2024-01']['net_sales_units']);
        self::assertSame('-1.250000', $result->months['2024-02']['net_sales_units']);
        $zero = $this->calculate([$this->sale('1', '2024-01-01', '1'), $this->sale('2', '2024-01-01', '-1', 'return')]);
        self::assertSame('0.000000', $zero->summary['net_sales_units']);
    }

    /**
     * Comprueba año bisiesto, cambio de año y ventanas solapadas con corte exclusivo.
     * @return void
     */
    public function testLeapYearMonthsYearsAndOverlappingWindows(): void
    {
        $result = $this->calculate([$this->sale('1', '2023-12-31', '1'), $this->sale('2', '2024-02-29', '2'), $this->sale('3', '2024-12-31', '3')], '2023-12-01', '2025-01-01');
        self::assertSame(29, $result->months['2024-02']['calendar_days']);
        self::assertSame(366, $result->years[2024]['calendar_days']);
        self::assertSame('1.000000', $result->years[2023]['net_sales_units']);
        self::assertTrue($result->years[2023]['partial_calendar_period']);
        self::assertSame('5.000000', $result->windows[365]['net_sales_units']);
        self::assertSame('3.000000', $result->windows[30]['net_sales_units']);
        self::assertSame('3.000000', $result->windows[90]['net_sales_units']);
        self::assertNull($result->windows[365]['net_change_percent']);
    }

    /**
     * Los límites inválidos del origen no se ignoran para producir totales aparentemente correctos.
     * @return void
     */
    public function testOutOfRangeEventInvalidatesInterval(): void
    {
        $result = $this->calculate([$this->sale('1', '2024-02-01', '1')], '2024-01-01', '2024-02-01');
        self::assertSame('incomplete', $result->summary['observation_status']);
        self::assertNull($result->summary['net_sales_units']);
        self::assertContains('event_outside_interval', $result->summary['sales_quality_issues']);
    }

    /**
     * Tipo o unidad ausentes no se deducen del signo; el constructor antiguo continúa disponible.
     * @return void
     */
    public function testUnknownClassificationAndUnit(): void
    {
        $legacy = new SaleData('1', 'P', 'L', '1', $this->date('2024-01-01'));
        self::assertNull($legacy->kind);
        $result = $this->calculate([$legacy, $this->sale('2', '2024-01-02', '2', 'sale', null)]);
        self::assertNull($result->summary['gross_sales_units']);
        self::assertContains('event_kind_unverified', $result->summary['sales_quality_issues']);
        self::assertContains('event_unit_unverified', $result->summary['sales_quality_issues']);
    }

    /**
     * Las discrepancias de stock y el orden intradía no descartan ventas comerciales verificadas.
     * @return void
     */
    public function testInventoryQualityIsIndependent(): void
    {
        $date = $this->date('2024-01-01');
        $movement = new InventoryMovementData('1', 'P', 'L', 'D', 'N', 'original', $date, '2', '2', '-2', '2', 'U', 'U', '999', 'day', ['stock_delta_mismatch', 'same_day_order_ambiguous', 'time_of_day_unknown']);
        $result = $this->calculate([new SaleData('1', 'P', 'L', '2', $date, $movement, 'sale', 'U')]);
        self::assertSame('2.000000', $result->summary['net_sales_units']);
        self::assertSame('complete', $result->summary['observation_status']);
        self::assertSame([], $result->summary['sales_quality_issues']);
        self::assertContains('stock_delta_mismatch', $result->summary['inventory_quality_issues']);
    }

    /**
     * Un fallo después de emitir eventos invalida todos los días y no conserva sumas parciales.
     * @return void
     */
    public function testLateSourceFailure(): void
    {
        $result = $this->calculate([$this->sale('1', '2024-01-01', '100')], '2024-01-01', '2024-02-01', true);
        self::assertNull($result->summary['net_sales_units']);
        self::assertNull($result->days['2024-01-31']['net_sales_units']);
        self::assertSame(0, $result->summary['observed_days']);
        self::assertContains('source_failure', $result->summary['sales_quality_issues']);
    }

    /**
     * Detecta una identidad repetida entre bloques externos sin retener todos los movimientos.
     * @return void
     */
    public function testDuplicatesAcrossExternalSortBlocks(): void
    {
        $events = (function (): iterable {
            for ($id = 0; $id < 8200; $id++) { yield $this->sale((string) $id, '2024-01-01', '0.01'); }
            yield $this->sale('1', '2024-01-01', '0.01');
        })();
        $result = $this->calculate($events, '2024-01-01', '2024-01-02');
        self::assertNull($result->summary['net_sales_units']);
        self::assertContains('duplicate_identity', $result->summary['sales_quality_issues']);
    }

    /**
     * El origen vacío confirma ausencia de ventas registradas, nunca agotamiento.
     * @return void
     */
    public function testEmptyIntervalAndMissingWindowCoverage(): void
    {
        $result = $this->calculate([], '2024-01-01', '2024-01-03');
        self::assertSame('0.000000', $result->summary['net_sales_units']);
        self::assertSame(2, $result->summary['days_without_commercial_events']);
        self::assertNull($result->summary['average_net_per_sales_day']);
        self::assertNull($result->summary['average_monthly_net']);
        self::assertNull($result->windows[30]['net_sales_units']);
    }

    /**
     * La comparación usa períodos adyacentes no solapados y no divide por cero.
     * @return void
     */
    public function testComparablePeriodVariation(): void
    {
        $result = $this->calculate([$this->sale('1', '2024-03-15', '2'), $this->sale('2', '2024-04-15', '3')], '2024-01-01', '2024-05-01');
        self::assertSame('1.000000', $result->windows[30]['net_change_units']);
        self::assertSame('50.000000', $result->windows[30]['net_change_percent']);
        self::assertNull($result->windows[60]['net_change_percent']);
    }

    /**
     * Producto, ubicación y unidad no se mezclan silenciosamente.
     * @return void
     */
    public function testScopeAndUnitConsistency(): void
    {
        $mixed = new SaleData('1', 'OTHER', 'L', '1', $this->date('2024-01-01'), null, 'sale', 'U');
        self::assertNull($this->calculate([$mixed])->summary['net_sales_units']);
        $units = $this->calculate([$this->sale('1', '2024-01-01', '1'), $this->sale('2', '2024-01-02', '1', 'sale', 'OTHER')]);
        self::assertContains('series_unit_inconsistent', $units->summary['sales_quality_issues']);
    }

    /**
     * El corte predeterminado respeta el día local, incluso cuando UTC ya cambió de fecha.
     * @return void
     */
    public function testDefaultCutoffExcludesCurrentOperatingDay(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-11-08T02:00:00Z'));
        $fixture = new ObservedSalesFixture([]);
        $this->app->instance(ObservedSalesFixture::class, $fixture);
        $this->app['config']->set('replenishment.adapters.sales', ObservedSalesFixture::class);
        $result = $this->app->make(ObservedDemandQuery::class)->analyze('P', 'L');
        self::assertSame('2026-11-07', $result->until->format('Y-m-d'));
        self::assertSame(365, $result->summary['calendar_days']);
    }

    /**
     * Reconstruye días, conserva históricos y no consulta ni escribe en la conexión ERP.
     * @return void
     */
    public function testCacheRebuildMetricsAndConnectionIsolation(): void
    {
        $this->app->make(PackageMigrator::class)->run();
        $erp = $this->app['db']->connection('erp');
        $erp->enableQueryLog();
        $store = $this->app->make(ObservedDemandStore::class);
        $first = $store->persist($this->calculate([$this->sale('1', '2024-01-01', '1.125')], '2024-01-01', '2024-01-03'));
        $second = $store->persist($this->calculate([$this->sale('1', '2024-01-01', '2.125')], '2024-01-01', '2024-01-03'));
        self::assertSame(2, DailySale::count());
        self::assertSame(2, ProductMetric::count());
        self::assertSame('1.125000', $first->fresh()->net_sales);
        self::assertSame('2.125000', $second->net_sales);
        self::assertNull($second->stockout_days);
        self::assertNull($second->available_days);
        self::assertNull($second->recommended_safety_stock);
        self::assertNull($second->confidence);
        self::assertNull($second->demand_30_days);
        $cached = $store->read('P', 'L', $this->date('2024-01-01'), $this->date('2024-01-03'), 'America/Caracas');
        self::assertSame('2.125000', $cached->summary['net_sales_units']);
        self::assertSame('cache', $cached->origin);
        self::assertSame([], $erp->getQueryLog());
        $other = $store->read('P', 'OTHER', $this->date('2024-01-01'), $this->date('2024-01-03'), 'America/Caracas');
        self::assertNull($other->summary['net_sales_units']);
        $failed = $this->calculate([], '2024-01-01', '2024-01-03', true);
        try { $store->persist($failed); self::fail('Debe rechazarse la reconstrucción incompleta.'); }
        catch (\InvalidArgumentException $error) { self::assertStringContainsString('incompleta', $error->getMessage()); }
        self::assertSame('2.125000', DailySale::first()->net_sales_units);
        self::assertSame(2, ProductMetric::count());
        self::assertSame('1.125000', $first->fresh()->net_sales);
        $this->expectException(\LogicException::class);
        $first->update(['net_sales' => '4']);
    }

    /**
     * Si falla la métrica final, tampoco quedan visibles los bloques diarios ya escritos.
     * @return void
     */
    public function testPersistenceRollsBackWholeResult(): void
    {
        $this->app->make(PackageMigrator::class)->run();
        ProductMetric::creating(function (): void { throw new RuntimeException('Fallo sintético de publicación.'); });
        try {
            $this->app->make(ObservedDemandStore::class)->persist($this->calculate([], '2024-01-01', '2024-03-01'));
            self::fail('La publicación debe fallar.');
        } catch (RuntimeException $error) {
            self::assertSame(0, DailySale::count());
            self::assertSame(0, ProductMetric::count());
        } finally { ProductMetric::flushEventListeners(); ProductMetric::clearBootedModels(); }
    }

    /**
     * La ampliación revierte sin eliminar las doce tablas previas y sólo registra en el destino propio.
     * @return void
     */
    public function testMigrationEvolutionAndReversal(): void
    {
        $this->app->make(PackageMigrator::class)->run();
        $own = $this->app['db']->connection('own');
        $expected = [
            '2026_11_07_000001_create_replenishment_tables', '2026_11_08_000002_add_observed_sales',
            '2026_11_09_000003_add_supplier_lead_time_evidence', '2026_11_10_000004_create_supplier_imports',
            '2026_11_10_000005_add_proposal_workflow', '2026_11_10_000006_add_policy_generation',
            '2026_11_18_000007_create_commercial_statistics', '2026_11_19_000008_add_policy_demand_settings', '2026_11_19_000009_add_generation_work',
            '2026_11_23_000010_create_parameter_reviews','2026_11_24_000011_create_parameter_applications',
        ];
        self::assertSame($expected, $own->table('replenishment_migrations')->orderBy('id')->pluck('migration')->all());
        $schema = $own->getSchemaBuilder();
        self::assertTrue($schema->hasColumn('replenishment_product_metrics', 'reversed_returns'));
        self::assertTrue($schema->hasTable('replenishment_sales_unit_totals'));
        // Reproduce instalaciones sucesivas en SQLite; la reversión usa el migrador y su orden real.
        foreach ($expected as $i => $name) {
            $own->table('replenishment_migrations')->where('migration', $name)->update(['batch' => $i + 1]);
        }
        for ($remaining = count($expected) - 1; $remaining >= 1; $remaining--) {
            $this->app->make(PackageMigrator::class)->run(true);
            self::assertSame(array_slice($expected, 0, $remaining),
                $own->table('replenishment_migrations')->orderBy('id')->pluck('migration')->all());
            self::assertTrue($schema->hasTable('replenishment_product_metrics'));
            self::assertSame($remaining >= 2, $schema->hasTable('replenishment_daily_sales'));
        }
        self::assertFalse($schema->hasColumn('replenishment_product_metrics','reversed_returns'));
        self::assertFalse($this->app['db']->connection('erp')->getSchemaBuilder()->hasTable('replenishment_daily_sales'));
    }

    /**
     * Reconstruir un subintervalo conserva los días y alcances ajenos y detecta caché incompatible.
     * @return void
     */
    public function testPartialRebuildAndCacheVersion(): void
    {
        $this->app->make(PackageMigrator::class)->run();
        $store = $this->app->make(ObservedDemandStore::class);
        $store->persist($this->calculate([$this->sale('1', '2024-01-01', '1'), $this->sale('2', '2024-01-02', '2')], '2024-01-01', '2024-01-03'));
        $store->persist($this->calculate([], '2024-01-01', '2024-01-02'));
        $read = $store->read('P', 'L', $this->date('2024-01-01'), $this->date('2024-01-03'), 'America/Caracas');
        self::assertSame('2.000000', $read->summary['net_sales_units']);
        self::assertSame('0.000000', $read->days['2024-01-01']['net_sales_units']);
        self::assertNotNull($read->summary['cache_calculated_at_min']);
        self::assertNull($read->summary['unestimated']['lost_demand_units']);
        $row = DailySale::where('sales_date', '2024-01-02')->first();
        $row->update(['algorithm_version' => 'old-version']);
        $invalid = $store->read('P', 'L', $this->date('2024-01-01'), $this->date('2024-01-03'), 'America/Caracas');
        self::assertNull($invalid->summary['net_sales_units']);
        self::assertContains('cache_version_or_timezone_mismatch', $invalid->summary['sales_quality_issues']);
        $this->expectException(\InvalidArgumentException::class);
        $store->persist($read);
    }

    /**
     * No modifica signos incompatibles ni mezcla el cambio de fecha UTC con el calendario operativo.
     * @return void
     */
    public function testSignAndLocalDateBoundaries(): void
    {
        $bad = $this->calculate([$this->sale('1', '2024-01-01', '1', 'return')]);
        self::assertContains('event_sign_inconsistent', $bad->summary['sales_quality_issues']);
        $event = new SaleData('1', 'P', 'L', '0.125', new DateTimeImmutable('2024-02-01T02:00:00Z'), null, 'sale', 'U');
        $result = $this->calculate([$event], '2024-01-01', '2024-02-01');
        self::assertSame('0.125000', $result->days['2024-01-31']['net_sales_units']);
        self::assertSame(31, $result->summary['calendar_days']);
    }
}

