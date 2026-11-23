<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Asisnet\Replenishment\Database\Migrations\CreateReplenishmentTables;
use Asisnet\Replenishment\Models\DemandForecast;
use Asisnet\Replenishment\Models\Policy;
use Asisnet\Replenishment\Models\ProductMetric;
use Asisnet\Replenishment\Models\ProductSupplier;
use Asisnet\Replenishment\Models\PurchaseProposal;
use Asisnet\Replenishment\Models\PurchaseProposalItem;
use Asisnet\Replenishment\Models\PurchaseUnit;
use Asisnet\Replenishment\Models\ReplenishmentResult;
use Asisnet\Replenishment\Models\ReplenishmentRun;
use Asisnet\Replenishment\Models\SeasonalPattern;
use Asisnet\Replenishment\Models\SupplierMetric;
use Asisnet\Replenishment\Models\SupplierPresentation;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Orchestra\Testbench\TestCase;

/**
 * Verifica persistencia exclusivamente en SQLite en memoria y compila SQL Server sin conexión.
 */
final class PersistenceTest extends TestCase
{
    /**
     * Carga el proveedor real para verificar también el registro de migraciones.
     *
     * @param  \Illuminate\Foundation\Application  $app  Aplicación aislada.
     * @return array<class-string> Proveedor del paquete.
     */
    protected function getPackageProviders($app): array
    {
        return [ReplenishmentServiceProvider::class];
    }

    /**
     * Sobrescribe explícitamente las conexiones para impedir acceso a bases del consumidor.
     *
     * @param  \Illuminate\Foundation\Application  $app  Aplicación aislada.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('replenishment.connection', 'testing');
    }

    /**
     * Migra una base nueva en memoria antes de cada caso.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('replenishment:migrate', ['--force' => true])->assertExitCode(0);
    }

    /**
     * Comprueba la creación y reversión de todas las tablas sin tocar tablas ajenas.
     */
    public function testMigrationsAndRollbackPreserveUnrelatedTables(): void
    {
        Schema::create('unrelated_records', function ($table): void {
            $table->id();
        });
        $tables = [
            'purchase_units', 'product_suppliers', 'supplier_presentations', 'policies',
            'product_metrics', 'supplier_metrics', 'seasonal_patterns', 'demand_forecasts',
            'runs', 'results', 'purchase_proposals', 'purchase_proposal_items',
        ];
        foreach ($tables as $table) {
            self::assertTrue(Schema::hasTable('replenishment_'.$table));
        }

        $this->artisan('replenishment:migrate', ['--rollback' => true, '--force' => true])->assertExitCode(0);

        foreach ($tables as $table) {
            self::assertFalse(Schema::hasTable('replenishment_'.$table));
        }
        self::assertTrue(Schema::hasTable('unrelated_records'));
    }

    /**
     * Verifica proveedores múltiples y presentaciones múltiples por cada relación.
     */
    public function testMultipleSuppliersAndPresentationsAreIndependent(): void
    {
        $first = $this->presentation();
        $second = $this->presentation('0001', '0002', 'PALLET');
        $third = $this->presentation('0001', '0003');

        self::assertSame(2, ProductSupplier::where('product_id', '0001')->count());
        self::assertSame(2, $first->productSupplier->presentations()->count());
        self::assertSame($first->product_supplier_id, $second->product_supplier_id);
        self::assertNotSame($first->product_supplier_id, $third->product_supplier_id);
        self::assertSame('0001', $first->productSupplier->product_id);
    }

    /**
     * Verifica unicidad de códigos en la base, incluso usando SQL mediante query builder.
     */
    public function testPurchaseUnitCodeIsUnique(): void
    {
        PurchaseUnit::create(['code' => 'BOX', 'name' => 'Caja']);
        $this->expectException(QueryException::class);
        DB::table('replenishment_purchase_units')->insert(['code' => 'BOX', 'name' => 'Duplicada']);
    }

    /**
     * Impide duplicar una relación comercial dentro de la conexión.
     */
    public function testProductSupplierPairIsUnique(): void
    {
        $this->presentation();
        $this->expectException(QueryException::class);
        ProductSupplier::create(['product_id' => '0001', 'supplier_id' => '0002']);
    }

    /**
     * Permite una nueva revisión y rechaza repetir la misma presentación y revisión.
     */
    public function testPresentationRevisionsAreUnique(): void
    {
        $first = $this->presentation();
        $second = $this->presentation('0001', '0002', 'BOX', ['revision' => 2, 'base_units_per_package' => '24']);
        self::assertNotSame($first->id, $second->id);
        $this->expectException(QueryException::class);
        $this->presentation();
    }

    /**
     * Verifica claves foráneas reales en inserciones que no pasan por modelos.
     */
    public function testForeignKeysRejectOrphanPresentations(): void
    {
        $this->expectException(QueryException::class);
        DB::table('replenishment_supplier_presentations')->insert([
            'product_supplier_id' => 999,
            'purchase_unit_id' => 999,
            'base_units_per_package' => '1.000000',
            'minimum_packages' => '0.000000',
            'package_multiple' => '1.000000',
        ]);
    }

    /**
     * Comprueba precisión exacta más allá del rango seguro de coma flotante.
     */
    public function testDecimalsRoundTripWithoutPrecisionLoss(): void
    {
        $presentation = $this->presentation('0001', '0002', 'BOX', [
            'base_units_per_package' => '999999999999999999.123456',
            'minimum_packages' => '0.000001',
            'package_multiple' => '0.125',
        ])->fresh();

        self::assertSame('999999999999999999.123456', $presentation->base_units_per_package);
        self::assertSame('0.000001', $presentation->minimum_packages);
        self::assertSame('0.125000', $presentation->package_multiple);
        self::assertSame('text', DB::selectOne('select typeof(base_units_per_package) as storage_type from replenishment_supplier_presentations')->storage_type);
        self::assertLessThan(0, ExactDecimal::compare('-100.01', '-2'));
        self::assertGreaterThan(0, ExactDecimal::compare('100', '2.99'));
    }

    /**
     * Rechaza números float antes de que lleguen al controlador.
     */
    public function testFloatInputIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SupplierPresentation(['base_units_per_package' => 1.25]);
    }

    /**
     * Impide redondear silenciosamente fracciones que exceden la escala.
     */
    public function testExcessDecimalScaleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SupplierPresentation(['base_units_per_package' => '1.0000001']);
    }

    /**
     * Impide desbordar la precisión decimal declarada.
     */
    public function testDecimalOverflowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SupplierPresentation(['base_units_per_package' => '1000000000000000000']);
    }

    /**
     * Rechaza cada conversión, mínimo o múltiplo comercial inválido.
     */
    public function testInvalidCommercialQuantitiesAreRejected(): void
    {
        foreach (['base_units_per_package' => '0', 'minimum_packages' => '-1', 'package_multiple' => '0'] as $field => $value) {
            try {
                $this->presentation('0001', '0002', 'BOX', [$field => $value]);
                self::fail("Se aceptó un valor inválido en {$field}.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    /**
     * Evita aceptar IDs numéricos que ya hayan perdido su representación original.
     */
    public function testExternalIdentifiersRequireStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProductSupplier(['product_id' => 1, 'supplier_id' => '0002']);
    }

    /**
     * Mantiene independientes productos y ubicaciones, sin interpretar NULL como cero.
     */
    public function testMetricsPreserveLocationScopeAndUnknownValues(): void
    {
        foreach ([['0001', '01'], ['0001', '02'], ['0002', '01'], ['0001', null]] as [$product, $location]) {
            ProductMetric::create(array_merge($this->analysis(), [
                'product_id' => $product,
                'location_id' => $location,
                'calculated_at' => '2026-11-07 10:00:00',
            ]));
        }

        $metric = ProductMetric::where('product_id', '0001')->where('location_id', '01')->sole();
        self::assertNull($metric->daily_demand);
        self::assertNull($metric->on_hand);
        self::assertNull($metric->confidence);
        self::assertSame(1, ProductMetric::whereNull('location_id')->count());
        self::assertSame(4, ProductMetric::count());
    }

    /**
     * No inventa indicadores de proveedores sin evidencia de órdenes.
     */
    public function testSupplierMetricsKeepMissingEvidenceNull(): void
    {
        $presentation = $this->presentation();
        $metric = SupplierMetric::create(array_merge($this->analysis(), [
            'product_supplier_id' => $presentation->product_supplier_id,
            'calculated_at' => '2026-11-07 10:00:00',
        ]))->fresh();

        self::assertNull($metric->verified_orders);
        self::assertNull($metric->average_lead_time_days);
        self::assertNull($metric->confidence);
        self::assertSame($presentation->product_supplier_id, $metric->productSupplier->id);
    }

    /**
     * Conserva la presentación histórica aunque cambien nombres y revisiones vigentes.
     */
    public function testResultSnapshotSurvivesConfigurationChanges(): void
    {
        $presentation = $this->presentation();
        $run = ReplenishmentRun::create($this->analysis());
        $result = ReplenishmentResult::create([
            'replenishment_run_id' => $run->id,
            'product_id' => '0001',
            'supplier_id' => '0002',
            'location_id' => '01',
            'supplier_presentation_id' => $presentation->id,
            'suggested_packages' => '2',
        ]);
        $presentation->purchaseUnit->update(['name' => 'Nombre posterior']);
        $presentation->update(['active' => false]);
        $this->presentation('0001', '0002', 'BOX', ['revision' => 2, 'base_units_per_package' => '24']);

        $snapshot = $result->fresh()->presentation_snapshot;
        self::assertSame('Presentación BOX', $snapshot['purchase_unit_name']);
        self::assertSame('12.000001', $snapshot['base_units_per_package']);
        self::assertSame(1, $snapshot['revision']);
        self::assertSame(1, $run->results()->count());
        self::assertSame($run->id, $result->run->id);
    }

    /**
     * Protege resultados históricos de actualización individual.
     */
    public function testResultsCannotBeOverwritten(): void
    {
        $run = ReplenishmentRun::create($this->analysis());
        $result = ReplenishmentResult::create(['replenishment_run_id' => $run->id, 'product_id' => '0001']);
        $this->expectException(LogicException::class);
        $result->update(['available_stock' => '100']);
    }

    /**
     * Protege históricos de eliminación individual.
     */
    public function testHistoricalMetricsCannotBeDeleted(): void
    {
        $metric = ProductMetric::create(array_merge($this->analysis(), [
            'product_id' => '0001', 'calculated_at' => '2026-11-07 10:00:00',
        ]));
        $this->expectException(LogicException::class);
        $metric->delete();
    }

    /**
     * Verifica que una eliminación de cabecera no borre resultados en cascada.
     */
    public function testRunDeletionIsRestrictedByHistoricalResults(): void
    {
        $run = ReplenishmentRun::create($this->analysis());
        ReplenishmentResult::create(['replenishment_run_id' => $run->id, 'product_id' => '0001']);
        $this->expectException(QueryException::class);
        DB::table('replenishment_runs')->where('id', $run->id)->delete();
    }

    /**
     * Exige una nueva revisión para modificar conversiones ya registradas.
     */
    public function testPresentationConversionCannotBeRewritten(): void
    {
        $presentation = $this->presentation();
        $this->expectException(LogicException::class);
        $presentation->update(['base_units_per_package' => '48']);
    }

    /**
     * Rechaza presentaciones que pertenezcan a otro producto.
     */
    public function testResultCannotSelectAnotherProductPresentation(): void
    {
        $presentation = $this->presentation();
        $run = ReplenishmentRun::create($this->analysis());
        $this->expectException(InvalidArgumentException::class);
        ReplenishmentResult::create([
            'replenishment_run_id' => $run->id, 'product_id' => 'OTHER',
            'supplier_id' => '0002', 'supplier_presentation_id' => $presentation->id,
        ]);
    }

    /**
     * Persiste ajustes y moneda sin cambiar cantidades sugeridas ni emitir compras.
     */
    public function testProposalItemsAreEditableAndKeepTheirSnapshot(): void
    {
        $presentation = $this->presentation();
        $proposal = PurchaseProposal::create(['supplier_id' => '0002', 'location_id' => '01']);
        $item = PurchaseProposalItem::create([
            'purchase_proposal_id' => $proposal->id, 'product_id' => '0001',
            'supplier_presentation_id' => $presentation->id,
            'suggested_base_units' => '24.000002', 'suggested_packages' => '2',
            'estimated_unit_cost' => '123456789012.123456', 'currency_code' => 'USD',
        ]);
        $snapshot = $item->presentation_snapshot;
        $item->update(['approved_packages' => '3', 'approved_base_units' => '36.000003', 'adjustment_reason' => 'Ajuste explícito de prueba']);

        self::assertSame('2.000000', $item->fresh()->suggested_packages);
        self::assertSame('3.000000', $item->fresh()->approved_packages);
        self::assertSame('123456789012.123456', $item->fresh()->estimated_unit_cost);
        self::assertSame($snapshot, $item->fresh()->presentation_snapshot);
        self::assertSame(1, $proposal->items()->count());
        self::assertSame($proposal->id, $item->proposal->id);
    }

    /**
     * Rechaza una presentación de proveedor distinto a la cabecera.
     */
    public function testProposalRejectsAnotherSupplierPresentation(): void
    {
        $presentation = $this->presentation();
        $proposal = PurchaseProposal::create(['supplier_id' => 'OTHER']);
        $this->expectException(InvalidArgumentException::class);
        PurchaseProposalItem::create([
            'purchase_proposal_id' => $proposal->id, 'product_id' => '0001',
            'supplier_presentation_id' => $presentation->id, 'suggested_base_units' => '12',
        ]);
    }

    /**
     * Mantiene desconocidas las presentaciones cuando no existe una selección explícita.
     */
    public function testProposalDoesNotInventCommercialPresentation(): void
    {
        $proposal = PurchaseProposal::create(['supplier_id' => '0002']);
        $item = PurchaseProposalItem::create([
            'purchase_proposal_id' => $proposal->id, 'product_id' => '0001', 'suggested_base_units' => '10',
        ])->fresh();

        self::assertNull($item->supplier_presentation_id);
        self::assertNull($item->suggested_packages);
        self::assertNull($item->presentation_snapshot);
        self::assertNull($item->approved_base_units);
    }

    /**
     * Acepta exclusivamente los estados documentados de ejecuciones y propuestas.
     */
    public function testRunAndProposalStatesAreValidated(): void
    {
        foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $status) {
            self::assertSame($status, ReplenishmentRun::create(array_merge($this->analysis(), ['status' => $status]))->status);
        }
        foreach (['draft', 'in_review', 'approved', 'rejected', 'cancelled'] as $status) {
            self::assertSame($status, PurchaseProposal::create(['supplier_id' => '0002', 'status' => $status])->status);
        }
        foreach ([new ReplenishmentRun($this->analysis()), new PurchaseProposal(['supplier_id' => '0002'])] as $model) {
            try {
                $model->status = 'unknown';
                $model->save();
                self::fail('Se aceptó un estado no definido.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('status', $exception->getMessage());
            }
        }
    }

    /**
     * Persiste parámetros JSON como texto compatible con SQL Server 2017.
     */
    public function testRunParametersRoundTripAsJsonText(): void
    {
        $parameters = ['quantity' => '0.000001', 'context' => ['product_id' => '0001']];
        $run = ReplenishmentRun::create(array_merge($this->analysis(), ['parameters_json' => $parameters]));
        self::assertSame($parameters, $run->fresh()->parameters_json);
        self::assertIsString(DB::table('replenishment_runs')->value('parameters_json'));
    }

    /**
     * Conserva pronósticos de diferentes períodos y nuevas versiones del mismo período.
     */
    public function testForecastPeriodsAndVersionsRemainHistorical(): void
    {
        $data = [
            'product_id' => '0001', 'location_id' => '01', 'generated_at' => '2026-11-07 10:00:00',
            'period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00',
            'granularity' => 'monthly', 'method' => 'explicit-test', 'algorithm_version' => 'v1',
        ];
        DemandForecast::create($data);
        DemandForecast::create(array_merge($data, ['algorithm_version' => 'v2']));
        DemandForecast::create(array_merge($data, ['period_start' => '2027-01-01 00:00:00', 'period_end' => '2027-02-01 00:00:00']));
        self::assertSame(3, DemandForecast::count());
        self::assertNull(DemandForecast::first()->forecast_quantity);
    }

    /**
     * Rechaza ventanas invertidas sin ejecutar fórmulas.
     */
    public function testAnalysisIntervalMustBeIncreasing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReplenishmentRun::create(array_merge($this->analysis(), ['analysis_end' => '2026-01-01 00:00:00']));
    }

    /**
     * Verifica límites mensuales y persistencia de un patrón semanal válido.
     */
    public function testSeasonalPeriodRangeIsValidated(): void
    {
        $data = ['product_id' => '0001', 'period_type' => 'weekly', 'period_number' => 53,
            'calculated_at' => '2026-11-07 10:00:00', 'algorithm_version' => 'v1'];
        self::assertNull(SeasonalPattern::create($data)->seasonal_index);
        $this->expectException(InvalidArgumentException::class);
        SeasonalPattern::create(array_merge($data, ['period_type' => 'monthly', 'period_number' => 13]));
    }

    /**
     * Una política incompleta permanece inactiva sin valores comerciales inventados.
     */
    public function testPoliciesRequireExplicitParametersBeforeActivation(): void
    {
        $policy = Policy::create(['name' => 'Pendiente']);
        self::assertFalse($policy->active);
        self::assertNull($policy->service_level);
        self::assertNull($policy->analysis_days);
        $this->expectException(InvalidArgumentException::class);
        $policy->update(['active' => true]);
    }

    /**
     * Utiliza parámetros configurados explícitamente sin imponer valores propios del paquete.
     */
    public function testPolicyDefaultsAreConfigurable(): void
    {
        $this->app['config']->set('replenishment.policy_defaults', [
            'analysis_days' => 90, 'target_coverage_days' => 15, 'service_level' => '0.95',
            'default_lead_time_days' => 3, 'minimum_history_days' => 30,
            'seasonal_minimum_years' => 2, 'forecast_horizon_days' => 30,
        ]);
        $policy = Policy::create(['name' => 'Parámetros de prueba', 'active' => true])->fresh();
        self::assertSame('0.950000', $policy->service_level);
        self::assertSame(90, $policy->analysis_days);
    }

    /**
     * Compila DDL SQL Server sin abrir PDO; esto no sustituye probar SQL Server 2017 real.
     */
    public function testSqlServerSchemaCompilesWithoutConnecting(): void
    {
        $connection = new SqlServerConnection(function (): void {
            throw new LogicException('La revisión de gramática no debe abrir conexiones.');
        }, 'isolated_review', '', ['driver' => 'sqlsrv']);
        DB::extend('sqlsrv', function () use ($connection): SqlServerConnection {
            return $connection;
        });
        $this->app['config']->set('database.connections.sqlsrv_review', ['driver' => 'sqlsrv', 'database' => 'isolated_review']);
        $this->app['config']->set('replenishment.connection', 'sqlsrv_review');
        $queries = DB::connection('sqlsrv_review')->pretend(function (): void {
            app(\Asisnet\Replenishment\Services\PersistenceConnection::class)->migrating(function (): void {
                (new CreateReplenishmentTables)->up();
                (new CreateReplenishmentTables)->down();
            });
        });
        $sql = implode("\n", array_column($queries, 'query'));
        $sql = str_replace(['[', ']'], '"', $sql);

        self::assertStringContainsString('decimal(24, 6)', $sql);
        self::assertStringContainsString('decimal(9, 6)', $sql);
        self::assertStringContainsString('"parameters_json" nvarchar(max)', $sql);
        self::assertStringNotContainsString('cascade', strtolower($sql));
        self::assertSame(12, substr_count(strtolower($sql), 'create table'));
        preg_match_all('/"(rep_[^"]+)"/', $sql, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $name) {
            self::assertLessThanOrEqual(128, strlen($name));
        }
    }

    /**
     * Comprueba que el esquema y los modelos usen la conexión elegida por el paquete.
     */
    public function testConfiguredConnectionIsIsolatedFromTheDefault(): void
    {
        $this->app['config']->set('database.connections.package_testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $this->app['config']->set('replenishment.connection', 'package_testing');
        app(\Asisnet\Replenishment\Services\PackageMigrator::class)->run();
        $this->presentation();

        self::assertSame(1, SupplierPresentation::count());
        self::assertSame(0, DB::connection('testing')->table('replenishment_supplier_presentations')->count());
        self::assertSame('0002', SupplierPresentation::first()->productSupplier->supplier_id);
        app(\Asisnet\Replenishment\Services\PackageMigrator::class)->run(true);
        self::assertTrue(Schema::connection('testing')->hasTable('replenishment_purchase_units'));
    }

    /**
     * No permite eliminar una propuesta que todavía posee líneas relacionadas.
     */
    public function testProposalDeletionDoesNotCascadeToItems(): void
    {
        $proposal = PurchaseProposal::create(['supplier_id' => '0002']);
        PurchaseProposalItem::create([
            'purchase_proposal_id' => $proposal->id, 'product_id' => '0001', 'suggested_base_units' => '10',
        ]);
        $this->expectException(QueryException::class);
        $proposal->delete();
    }

    /**
     * Preserva el contexto original de la ejecución después de almacenar resultados.
     */
    public function testRunContextCannotBeRewritten(): void
    {
        $run = ReplenishmentRun::create($this->analysis());
        $this->expectException(LogicException::class);
        $run->update(['algorithm_version' => 'replacement']);
    }

    /**
     * Conserva instantes absolutos y microsegundos al normalizar fechas a UTC.
     */
    public function testTimestampsAreNormalizedToUtcWithoutLosingMicroseconds(): void
    {
        $run = ReplenishmentRun::create(array_merge($this->analysis(), [
            'started_at' => new \DateTimeImmutable('2026-11-07T10:00:00.123456-04:00'),
        ]))->fresh();
        self::assertSame('2026-11-07 14:00:00.123456', $run->started_at->format('Y-m-d H:i:s.u'));
    }

    /**
     * Crea configuración comercial explícita compartida por las pruebas.
     *
     * @param  string  $product  Identificador externo exacto de producto.
     * @param  string  $supplier  Identificador externo exacto de proveedor.
     * @param  string  $unitCode  Código de presentación comercial de prueba.
     * @param  array<string, mixed>  $overrides  Valores suministrados explícitamente por el caso.
     * @return SupplierPresentation Presentación persistida en SQLite temporal.
     */
    private function presentation(string $product = '0001', string $supplier = '0002', string $unitCode = 'BOX', array $overrides = []): SupplierPresentation
    {
        $unit = PurchaseUnit::firstOrCreate(['code' => $unitCode], ['name' => 'Presentación '.$unitCode]);
        $supplierRelation = ProductSupplier::firstOrCreate(['product_id' => $product, 'supplier_id' => $supplier]);

        return SupplierPresentation::create(array_merge([
            'product_supplier_id' => $supplierRelation->id,
            'purchase_unit_id' => $unit->id,
            'base_units_per_package' => '12.000001',
            'minimum_packages' => '0',
            'package_multiple' => '1',
        ], $overrides));
    }

    /**
     * Proporciona metadatos de análisis de prueba sin generar métricas.
     *
     * @return array<string, string> Intervalo UTC y versión explícita.
     */
    private function analysis(): array
    {
        return ['analysis_start' => '2026-01-01 00:00:00', 'analysis_end' => '2026-11-01 00:00:00', 'algorithm_version' => 'test-v1'];
    }
}
