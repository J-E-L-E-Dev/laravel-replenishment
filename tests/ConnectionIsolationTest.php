<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\Models\ProductSupplier;
use Asisnet\Replenishment\Models\PurchaseUnit;
use Asisnet\Replenishment\Models\SupplierPresentation;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * Verifica aislamiento de tablas, registro, relaciones y transacciones en dos SQLite independientes.
 */
final class ConnectionIsolationTest extends TestCase
{
    /**
     * Carga los comandos y servicios reales del paquete.
     *
     * @param \Illuminate\Foundation\Application $app Aplicación de pruebas.
     * @return array<class-string> Proveedor probado.
     */
    protected function getPackageProviders($app): array
    {
        return [ReplenishmentServiceProvider::class];
    }

    /**
     * Declara dos bases en memoria sin configuración ni credenciales del consumidor.
     *
     * @param \Illuminate\Foundation\Application $app Aplicación aislada.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach (['application', 'package'] as $name) {
            $app['config']->set("database.connections.{$name}", [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
        $app['config']->set('database.default', 'application');
        $app['config']->set('replenishment.connection', 'package');
    }

    /**
     * Prepara un registro ajeno que debe sobrevivir a todas las operaciones propias.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('application')->getSchemaBuilder()->create('erp_marker', function ($table): void {
            $table->string('value');
        });
        DB::connection('application')->table('erp_marker')->insert(['value' => 'intacto']);
    }

    /**
     * Comprueba que tablas y registro se creen juntos, sin cambiar el destino global ni eventos.
     *
     * @return void
     */
    public function testMigrationAndRegistryUseOnlyThePackageConnection(): void
    {
        $this->app['events']->listen(MigrationStarted::class, function (): void {
            self::assertSame('application', DB::getDefaultConnection());
            self::assertSame('intacto', DB::table('erp_marker')->value('value'));
        });
        $this->artisan('replenishment:migrate')->assertSuccessful();
        $this->artisan('replenishment:migrate')->assertSuccessful();

        self::assertTrue(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertSame(11, DB::connection('package')->table('replenishment_migrations')->count());
        self::assertSame('2026_11_07_000001_create_replenishment_tables',
            DB::connection('package')->table('replenishment_migrations')->value('migration'));
        self::assertFalse(DB::connection('application')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertFalse(DB::connection('application')->getSchemaBuilder()->hasTable('replenishment_migrations'));
        self::assertSame('application', DB::getDefaultConnection());
        self::assertSame('intacto', DB::table('erp_marker')->value('value'));
    }

    /**
     * Una migración general no encuentra rutas del paquete ni crea tablas en otro destino.
     *
     * @return void
     */
    public function testGeneralMigrateAndRollbackDoNotExecutePackageMigrations(): void
    {
        self::assertNotContains(realpath(__DIR__.'/../database/migrations'), array_map('realpath', $this->app['migrator']->paths()));
        $this->artisan('migrate', ['--database' => 'application', '--force' => true])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--database' => 'application', '--force' => true])->assertSuccessful();
        self::assertFalse(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertFalse(DB::connection('application')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertSame('intacto', DB::table('erp_marker')->value('value'));
    }

    /**
     * Rechaza incluso una ruta manual del paquete en el migrador general.
     *
     * @return void
     */
    public function testManualGeneralMigrationPathIsRejectedBeforePackageDdl(): void
    {
        try {
            $this->artisan('migrate', [
                '--database' => 'application', '--path' => realpath(__DIR__.'/../database/migrations'),
                '--realpath' => true, '--force' => true,
            ])->run();
            self::fail('La ruta general no debe ejecutar migraciones propias.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('replenishment:migrate', $exception->getMessage());
        }
        self::assertFalse(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertFalse(DB::connection('application')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertSame(0, DB::connection('application')->table('migrations')->count());
    }

    /**
     * Revierte exclusivamente el lote propio y conserva tablas y registros ajenos en ambos destinos.
     *
     * @return void
     */
    public function testRollbackIsIsolatedEvenWithUnrelatedTablesOnTheSameConnection(): void
    {
        $this->artisan('replenishment:migrate')->assertSuccessful();
        $schema = DB::connection('package')->getSchemaBuilder();
        $schema->create('migrations', function ($table): void {
            $table->string('migration');
            $table->integer('batch');
        });
        DB::connection('package')->table('migrations')->insert(['migration' => 'external_migration', 'batch' => 99]);
        $this->artisan('replenishment:migrate', ['--rollback' => true])->assertSuccessful();
        self::assertFalse($schema->hasTable('replenishment_purchase_units'));
        self::assertSame(0, DB::connection('package')->table('replenishment_migrations')->count());
        self::assertSame('external_migration', DB::connection('package')->table('migrations')->value('migration'));
        self::assertSame('intacto', DB::connection('application')->table('erp_marker')->value('value'));
        $this->artisan('replenishment:migrate')->assertSuccessful();
        self::assertTrue($schema->hasTable('replenishment_purchase_units'));
    }

    /**
     * Todos los modelos comparten destino y las relaciones pueden leer y crear registros allí.
     *
     * @return void
     */
    public function testAllModelsAndRelationsUseTheConfiguredConnection(): void
    {
        $this->artisan('replenishment:migrate')->assertSuccessful();
        foreach ([
            'PurchaseUnit', 'ProductSupplier', 'SupplierPresentation', 'Policy', 'ProductMetric',
            'SupplierMetric', 'SeasonalPattern', 'DemandForecast', 'ReplenishmentRun',
            'ReplenishmentResult', 'PurchaseProposal', 'PurchaseProposalItem',
        ] as $shortName) {
            $class = 'Asisnet\\Replenishment\\Models\\'.$shortName;
            self::assertSame('package', (new $class)->getConnectionName());
        }

        $unit = PurchaseUnit::create(['code' => 'BOX', 'name' => 'Caja explícita']);
        $supplier = ProductSupplier::create(['product_id' => '0001', 'supplier_id' => '0002']);
        $supplier->presentations()->create([
            'purchase_unit_id' => $unit->id, 'base_units_per_package' => '12',
            'minimum_packages' => '0', 'package_multiple' => '1',
        ]);
        $presentation = SupplierPresentation::with(['purchaseUnit', 'productSupplier'])->sole();
        self::assertSame('0001', $presentation->productSupplier->product_id);
        self::assertSame('BOX', $presentation->purchaseUnit->code);
        self::assertSame(1, $unit->presentations()->count());
        self::assertSame(1, DB::connection('package')->table('replenishment_supplier_presentations')->count());
        self::assertSame('intacto', DB::connection('application')->table('erp_marker')->value('value'));
    }

    /**
     * Impide desviar un modelo con la API habitual Model::on.
     *
     * @return void
     */
    public function testModelConnectionOverrideIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PurchaseUnit::on('application')->count();
    }

    /**
     * Comprueba rollback transaccional en el destino propio sin involucrar la conexión ERP.
     *
     * @return void
     */
    public function testTransactionRollsBackOnlyPackageWrites(): void
    {
        $this->artisan('replenishment:migrate')->assertSuccessful();
        try {
            app(PersistenceConnection::class)->transaction(function ($connection): void {
                self::assertSame('package', $connection->getName());
                self::assertSame('application', DB::getDefaultConnection());
                PurchaseUnit::create(['code' => 'ROLLBACK', 'name' => 'Temporal']);
                throw new RuntimeException('Reversión de prueba');
            });
            self::fail('La transacción debe propagar el error.');
        } catch (RuntimeException $exception) {
            self::assertSame('Reversión de prueba', $exception->getMessage());
        }
        self::assertSame(0, PurchaseUnit::count());
        self::assertSame('intacto', DB::table('erp_marker')->value('value'));
        self::assertSame(0, DB::connection('package')->transactionLevel());
    }

    /**
     * Sin nombre explícito utiliza la conexión predeterminada tanto para modelos como para migraciones.
     *
     * @return void
     */
    public function testUnspecifiedConnectionUsesLaravelDefault(): void
    {
        $this->app['config']->set('replenishment.connection', null);
        $this->artisan('replenishment:migrate')->assertSuccessful();
        PurchaseUnit::create(['code' => 'DEFAULT', 'name' => 'Conexión predeterminada']);
        self::assertSame('application', (new PurchaseUnit)->getConnectionName());
        self::assertSame(11, DB::connection('application')->table('replenishment_migrations')->count());
        self::assertSame(1, DB::connection('application')->table('replenishment_purchase_units')->count());
        self::assertFalse(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        $this->artisan('replenishment:migrate', ['--rollback' => true])->assertSuccessful();
        self::assertSame('intacto', DB::table('erp_marker')->value('value'));
    }

    /**
     * Una conexión inexistente falla antes de crear tablas y produce un mensaje comprensible.
     *
     * @return void
     */
    public function testMissingConnectionFailsInCommandsAndModels(): void
    {
        $this->app['config']->set('replenishment.connection', 'missing_connection');
        $this->artisan('replenishment:migrate')
            ->expectsOutputToContain('REPLENISHMENT_DB_CONNECTION')->assertFailed();
        self::assertFalse(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_migrations'));
        self::assertSame('application', DB::getDefaultConnection());
        $this->expectException(InvalidArgumentException::class);
        PurchaseUnit::query()->count();
    }

    /**
     * No adopta tablas heredadas cuyo registro podría estar en otra base.
     *
     * @return void
     */
    public function testLegacyTablesRequireExplicitReconciliation(): void
    {
        DB::connection('package')->getSchemaBuilder()->create('replenishment_purchase_units', function ($table): void {
            $table->id();
        });
        $this->artisan('replenishment:migrate')
            ->expectsOutputToContain('Concilie las migraciones anteriores')->assertFailed();
        self::assertTrue(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertFalse(DB::connection('package')->getSchemaBuilder()->hasTable('replenishment_migrations'));
        self::assertFalse(app(PersistenceConnection::class)->isMigrating());
    }

    /**
     * Lee el nombre Laravel desde la variable de entorno sin interpretarlo como driver.
     *
     * @return void
     */
    public function testEnvironmentVariableConfiguresConnectionName(): void
    {
        $environment = Env::getRepository();
        $previous = $environment->get('REPLENISHMENT_DB_CONNECTION');
        try {
            $environment->set('REPLENISHMENT_DB_CONNECTION', 'inventory_sqlserver');
            $config = require __DIR__.'/../config/replenishment.php';
            self::assertSame('inventory_sqlserver', $config['connection']);
        } finally {
            if ($previous === null) {
                $environment->clear('REPLENISHMENT_DB_CONNECTION');
            } else {
                $environment->set('REPLENISHMENT_DB_CONNECTION', $previous);
            }
        }
    }
}

