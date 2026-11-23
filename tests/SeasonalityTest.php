<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Asisnet\Replenishment\Models\SeasonalPattern;
use Asisnet\Replenishment\Models\ProductMetric;
use Asisnet\Replenishment\Services\ObservedDemandCalculator;
use Asisnet\Replenishment\Services\SeasonalityAnalyzer;
use Asisnet\Replenishment\Services\SeasonalityStore;
use Asisnet\Replenishment\Services\PackageMigrator;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Orchestra\Testbench\TestCase;

/** Comprueba estacionalidad explicable con calendarios sintéticos y persistencia aislada. */
final class SeasonalityTest extends TestCase
{
    /**
     * Registra el proveedor propio.
     * @param mixed $app Aplicación.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array { return [ReplenishmentServiceProvider::class]; }
    /**
     * Declara conexiones independientes sin servicios reales.
     * @param mixed $app Aplicación.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach (['own', 'erp'] as $name) { $app['config']->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']); }
        $app['config']->set('database.default', 'erp'); $app['config']->set('replenishment.connection', 'own');
    }
    /**
     * Construye una serie diaria verificada sin datos comerciales reales.
     * @param callable $rate Tasa por fecha.
     * @param string $from Inicio.
     * @param string $until Corte.
     * @param array $overrides Días alterados.
     * @param string $product Producto.
     * @param string $location Ubicación.
     * @return ObservedDemandData Serie.
     */
    private function series(callable $rate, string $from = '2021-01-01', string $until = '2025-01-01', array $overrides = [], string $product = 'P', string $location = 'L'): ObservedDemandData
    {
        $start = new DateTimeImmutable($from.'T00:00:00+00:00'); $end = new DateTimeImmutable($until.'T00:00:00+00:00'); $days = [];
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $value = $rate($date); $key = $date->format('Y-m-d');
            $days[$key] = array_replace(['gross_sales_units' => $value, 'returned_units' => '0', 'reversed_return_units' => '0', 'net_sales_units' => $value,
                'event_count' => BigDecimal::of($value)->isPositive() ? 1 : 0, 'base_unit' => 'U', 'observation_status' => 'complete', 'quality_issues' => [], 'inventory_quality_issues' => []], $overrides[$key] ?? []);
        }
        return (new ObservedDemandCalculator())->fromDays($product, $location, $start, $end, 'UTC', $days);
    }
    /**
     * Evalúa datos con opciones por defecto.
     * @param ObservedDemandData $data Serie.
     * @return \Asisnet\Replenishment\DTO\SeasonalityData Diagnóstico.
     */
    private function analyze(ObservedDemandData $data) { return (new SeasonalityAnalyzer())->analyze($data, new SeasonalityOptions()); }
    /**
     * Una tasa diaria constante neutraliza febrero, meses largos y bisiestos.
     * @return void
     */
    public function testConstantCalendarLeapAndReproducibleDecimals(): void
    {
        $data = $this->series(fn ($d): string => '1.123456'); $result = $this->analyze($data);
        self::assertSame('none_detected', $result->report['quality']);
        foreach ($result->report['months'] as $month) { self::assertSame('1.000000000000', $month['index']); }
        self::assertSame(29, $result->report['observed_months']['2024-02']['days']);
        self::assertNull($result->report['peak_month']);
        self::assertSame($result->audit(), $this->analyze($data)->audit());
    }
    /**
     * Un pico repetido es descriptivamente recurrente, sin inventar confianza estadística.
     * @return void
     */
    public function testRecurringPeakAndSingleYearPeak(): void
    {
        $result = $this->analyze($this->series(fn ($d): string => $d->format('m') === '07' ? '30' : '10'));
        self::assertSame('recurrent', $result->report['quality']); self::assertSame(7, $result->report['peak_month']);
        self::assertSame('recurrent', $result->report['months'][7]['quality']); self::assertNull($result->report['confidence']);
        self::assertFalse($result->report['months'][7]['significant_after_12_tests']);
        $single = $this->analyze($this->series(fn ($d): string => $d->format('Y-m') === '2024-07' ? '300' : '10'));
        self::assertSame('possible', $single->report['months'][7]['quality']);
        self::assertCount(1, $single->report['months'][7]['high_years']);
        self::assertCount(4, $single->report['months'][7]['leave_one_year_out']);
    }
    /**
     * Crecimiento y descenso anuales no producen estacionalidad por escala.
     * @return void
     */
    public function testAnnualGrowthAndDecline(): void
    {
        foreach ([1 => 'increasing', -1 => 'decreasing'] as $direction => $expected) {
            $result = $this->analyze($this->series(fn ($d): string => (string) ($direction === 1 ? (int) $d->format('Y') - 2020 : 2026 - (int) $d->format('Y'))));
            self::assertSame('none_detected', $result->report['quality']); self::assertSame($expected, $result->report['trend']['classification']);
        }
        $withinYear = $this->analyze($this->series(fn ($d): string => (string) (int) $d->format('m')));
        self::assertCount(4, $withinYear->report['trend']['within_year_monotonic_years']);
        self::assertNotContains('recurrent', array_column($withinYear->report['months'], 'quality'));
    }
    /**
     * La temporada noviembre–enero requiere años adyacentes y no inventa enero del último año.
     * @return void
     */
    public function testSeasonAcrossYearBoundary(): void
    {
        $result = $this->analyze($this->series(fn ($d): string => in_array((int) $d->format('m'), [11,12,1], true) ? '30' : '10'));
        $season = $result->report['seasons'][0];
        self::assertSame(11, $season['start_month']); self::assertSame(1, $season['end_month']);
        self::assertSame([2021,2022,2023], $season['years_present']); self::assertSame([2024], $season['years_unassessed']);
        self::assertSame('recurrent', $season['quality']);
    }
    /**
     * Mes vacío verificado es cero; meses parciales y desconocidos no se normalizan como años enteros.
     * @return void
     */
    public function testZeroMonthPartialYearsUnknownUnitsAndShortHistory(): void
    {
        $zero = $this->analyze($this->series(fn ($d): string => $d->format('m') === '02' ? '0' : '1'));
        self::assertSame('0.000000000000', $zero->report['months'][2]['index']);
        self::assertSame('recurrent', $zero->report['months'][2]['quality']);
        $partial = $this->analyze($this->series(fn ($d): string => '1', '2022-01-15', '2024-12-20'));
        self::assertSame([2023], $partial->report['accepted_years']); self::assertSame('insufficient', $partial->report['quality']);
        self::assertNull($partial->report['months'][1]['index']);
        $unknown = $this->analyze($this->series(fn ($d): string => '1', overrides: ['2022-03-01' => ['observation_status' => 'incomplete', 'quality_issues' => ['event_unit_unverified']]]));
        self::assertArrayHasKey(2022, $unknown->report['excluded_years']);
        $units = $this->analyze($this->series(fn ($d): string => '1', overrides: ['2022-03-01' => ['base_unit' => 'OTHER']]));
        self::assertSame([], $units->report['accepted_years']);
        $missing = $this->analyze($this->series(fn ($d): string => '1', overrides: ['2022-03-01' => ['base_unit' => null]]));
        self::assertContains('unit_unverified', $missing->report['observed_months']['2022-03']['reasons']);
    }
    /**
     * Devoluciones posteriores y netos negativos no desplazan la señal bruta.
     * @return void
     */
    public function testReturnsDoNotChangeGrossSignal(): void
    {
        $base = $this->analyze($this->series(fn ($d): string => '1'));
        $changed = $this->analyze($this->series(fn ($d): string => '1', overrides: ['2024-02-15' => ['returned_units' => '100', 'net_sales_units' => '-99']]));
        self::assertSame($base->report['months'], $changed->report['months']);
        self::assertSame('-71.000000', $changed->report['observed_months']['2024-02']['net_sales_units']);
    }
    /**
     * Ausencia de actividad no significa ausencia de estacionalidad demostrada; extremos permanecen visibles.
     * @return void
     */
    public function testZeroActivityIntermittencyAndOutliers(): void
    {
        $empty = $this->analyze($this->series(fn ($d): string => '0'));
        self::assertSame('insufficient', $empty->report['quality']); self::assertSame([], $empty->report['accepted_years']);
        $intermittent = $this->analyze($this->series(fn ($d): string => $d->format('m-d') === '07-15' ? '1' : '0'));
        self::assertSame('insufficient', $intermittent->report['months'][7]['quality']);
        self::assertContains('insufficient_active_days', $intermittent->report['excluded_years'][2024]);
        self::assertSame(1, $intermittent->report['observed_months']['2024-07']['positive_sales_days']);
        $outlier = $this->analyze($this->series(fn ($d): string => $d->format('Y') === '2023' ? '100' : '1'));
        self::assertSame([2023], $outlier->report['trend']['atypical_scale_years']); self::assertCount(4, $outlier->report['accepted_years']);
        $unstable = $this->analyze($this->series(fn ($d): string => $d->format('m') === '07' ? ($d->format('Y') < '2023' ? '100' : '2') : '1'));
        self::assertSame('unstable', $unstable->report['months'][7]['quality']);
    }
    /**
     * La persistencia añade históricos y auditoría, conserva alcances y nunca usa la conexión ERP.
     * @return void
     */
    public function testVersionedPersistenceAndIsolation(): void
    {
        $this->app->make(PackageMigrator::class)->run(); $erp = $this->app['db']->connection('erp'); $erp->enableQueryLog();
        $result = $this->analyze($this->series(fn ($d): string => '1')); $store = $this->app->make(SeasonalityStore::class);
        $first = $store->persist($result); $second = $store->persist($result);
        self::assertSame(24, SeasonalPattern::count()); self::assertNotSame($first['pattern_ids'], $second['pattern_ids']);
        self::assertSame($result->report['accepted_years'], ProductMetric::find($first['audit_metric_id'])->observed_sales_summary['seasonality']['accepted_years']);
        self::assertNull(SeasonalPattern::first()->confidence); self::assertSame([], $erp->getQueryLog());
        $other = $this->analyze($this->series(fn ($d): string => '2', product: 'OTHER', location: 'SECOND')); $store->persist($other);
        self::assertSame(12, SeasonalPattern::where('product_id', 'OTHER')->where('location_id', 'SECOND')->count());
        $this->expectException(\LogicException::class); SeasonalPattern::first()->update(['seasonal_index' => '2']);
    }
    /**
     * La consulta exclusiva de caché respeta la política de mínimo sin resolver adaptadores ERP.
     * @return void
     */
    public function testCacheOnlyAndConfiguredMinimumYears(): void
    {
        $this->app->make(PackageMigrator::class)->run();
        $data = $this->series(fn ($d): string => '1', '2022-01-01', '2025-01-01');
        $this->app->make(\Asisnet\Replenishment\Services\ObservedDemandStore::class)->persist($data);
        $this->app['config']->set('app.timezone', 'UTC');
        $this->app['config']->set('replenishment.policy_defaults.seasonal_minimum_years', 4);
        $erp = $this->app['db']->connection('erp'); $erp->enableQueryLog();
        $result = $this->app->make(\Asisnet\Replenishment\Services\SeasonalityQuery::class)->analyze('P', 'L', $data->from, $data->until, true);
        self::assertSame('cache', $result->report['origin']); self::assertSame(4, $result->report['options']['minimumYears']);
        self::assertSame('insufficient', $result->report['quality']); self::assertSame([], $erp->getQueryLog());
        self::assertNotNull($result->report['cache_calculated_at_min']);
    }

    /**
     * Un fallo al publicar auditoría revierte también patrones; insuficiencia no genera índices.
     * @return void
     */
    public function testPersistenceAtomicityAndInsufficientRejection(): void
    {
        $this->app->make(PackageMigrator::class)->run(); $store = $this->app->make(SeasonalityStore::class);
        try { $store->persist($this->analyze($this->series(fn ($d): string => '0'))); self::fail('No debe persistir insuficientes.'); } catch (\InvalidArgumentException $error) { self::assertSame(0, SeasonalPattern::count()); }
        ProductMetric::creating(function (): void { throw new \RuntimeException('Fallo sintético.'); });
        try { $store->persist($this->analyze($this->series(fn ($d): string => '1'))); self::fail('Debe revertir el lote.'); }
        catch (\RuntimeException $error) { self::assertSame(0, SeasonalPattern::count()); }
        finally { ProductMetric::flushEventListeners(); }
    }
}
