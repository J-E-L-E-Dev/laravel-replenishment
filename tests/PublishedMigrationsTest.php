<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Asisnet\Replenishment\Services\PackageMigrator;
use Orchestra\Testbench\TestCase;
use LogicException;

/** Ejecuta migradores reales sobre dos destinos SQLite efímeros, sin publicar en el consumidor. */
final class PublishedMigrationsTest extends TestCase
{
    /**
     * Registra la integración real del paquete.
     * @param mixed $app Contenedor aislado.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array {return [ReplenishmentServiceProvider::class];}
    /**
     * Separa destino del paquete y aplicación en dos conexiones temporales.
     * @param mixed $app Aplicación de prueba.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach(['own','other'] as $name){$app['config']->set('database.connections.'.$name,['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);}
        $app['config']->set('database.default','other');$app['config']->set('replenishment.connection','own');
    }
    /**
     * Ejecuta Artisan con ruta aislada a los archivos publicables y conexión explícita.
     * @param string $command Comando migrate o migrate:rollback.
     * @param string $connection Destino solicitado.
     * @return void
     */
    private function migrate(string $command='migrate',string $connection='own'): void
    {
        $this->artisan($command,['--database'=>$connection,'--path'=>[realpath(__DIR__.'/../database/published')],'--realpath'=>true,'--force'=>true])->assertExitCode(0);
    }
    /**
     * El migrador estándar crea y revierte tablas y registro en el mismo destino.
     * @return void
     */
    public function testStandardInstallAndRollback(): void
    {
        $other=$this->app['db']->connection('other');$other->statement('CREATE TABLE sentinel (id INTEGER)');
        $this->migrate();$db=$this->app['db']->connection('own');
        self::assertSame(11,$db->table('migrations')->count());self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_supplier_imports'));
        self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_parameter_applications'));
        self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_parameter_reviews'));
        self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_generation_events'));
        self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_migrations'));
        self::assertFalse($other->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        $this->migrate('migrate:rollback');self::assertSame(0,$db->table('migrations')->count());
        self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_supplier_imports'));self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_parameter_applications'));
        self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_parameter_reviews'));
        self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_generations'));self::assertTrue($other->getSchemaBuilder()->hasTable('sentinel'));
    }
    /**
     * Un destino distinto no puede registrar migraciones de tablas propias.
     * @return void
     */
    public function testWrongConnectionFailsBeforeSchema(): void
    {
        try{$this->migrate('migrate','other');self::fail('Debió rechazar conexión distinta.');}catch(LogicException $error){self::assertStringContainsString('--database',$error->getMessage());}
        self::assertFalse($this->app['db']->connection('own')->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
        self::assertSame(0,$this->app['db']->connection('other')->table('migrations')->count());
    }
    /**
     * El historial dedicado permanece intacto cuando se intenta cambiar de modalidad.
     * @return void
     */
    public function testDedicatedHistoryCannotBeAdopted(): void
    {
        $this->app->make(PackageMigrator::class)->run();$db=$this->app['db']->connection('own');$before=$db->table('replenishment_migrations')->get()->toJson();
        try{$this->migrate();self::fail('Debió rechazar historial dedicado.');}catch(LogicException $error){self::assertStringContainsString('historial dedicado',$error->getMessage());}
        self::assertSame($before,$db->table('replenishment_migrations')->get()->toJson());self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_purchase_units'));
    }
    /**
     * La modalidad dedicada rechaza un historial estándar ya aplicado.
     * @return void
     */
    public function testStandardHistoryCannotBeAdopted(): void
    {
        $this->migrate();$this->expectException(LogicException::class);$this->app->make(PackageMigrator::class)->run();
    }
    /**
     * El fallback predeterminado y el tag de publicación conservan la separación optativa.
     * @return void
     */
    public function testDefaultConnectionAndPublishTag(): void
    {
        $this->app['config']->set('replenishment.connection',null);$this->migrate('migrate','other');
        self::assertSame(11,$this->app['db']->connection('other')->table('migrations')->count());
        $paths=\Illuminate\Support\ServiceProvider::pathsToPublish(ReplenishmentServiceProvider::class,'replenishment-migrations');
        self::assertCount(1,$paths);self::assertStringEndsWith('migrations/replenishment',str_replace('\\','/',array_values($paths)[0]));
        self::assertNotContains(realpath(__DIR__.'/../database/published'),array_map('realpath',$this->app['migrator']->paths()));
    }
    /**
     * La ampliación dedicada añade sólo su historial y puede revertirse sin borrar las tablas anteriores.
     * @return void
     */
    public function testExistingDedicatedInstallationUpgrade(): void
    {
        $service=$this->app->make(PackageMigrator::class);$service->run();$db=$this->app['db']->connection('own');
        // Representa una instalación previa aislada, nunca el consumidor real.
        $db->getSchemaBuilder()->drop('replenishment_supplier_imports');$db->table('replenishment_migrations')->where('migration','2026_11_10_000004_create_supplier_imports')->delete();
        $previous=$db->table('replenishment_migrations')->orderBy('id')->get()->toJson();$service->run();
        self::assertSame($previous,$db->table('replenishment_migrations')->where('batch',1)->orderBy('id')->get()->toJson());
        self::assertSame(11,$db->table('replenishment_migrations')->count());$service->run(true);
        self::assertSame(10,$db->table('replenishment_migrations')->count());self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_purchase_units'));self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_supplier_imports'));
    }
    /** Aplica y revierte sólo 000006 con registro dedicado, preservando una propuesta preexistente. @return void */
    public function testGenerationUpgradePreservesExistingProposal(): void
    {
        $service=$this->app->make(PackageMigrator::class);$service->run();$own=$this->app->make(\Asisnet\Replenishment\Services\PersistenceConnection::class);$db=$own->connection();
        $own->migrating(fn()=>(new \Asisnet\Replenishment\Database\Migrations\AddPolicyGeneration())->down());$db->table('replenishment_migrations')->where('migration','2026_11_10_000006_add_policy_generation')->delete();
        $proposal=\Asisnet\Replenishment\Models\PurchaseProposal::create(['supplier_id'=>'P','location_id'=>'L','created_by'=>'actor']);$before=$proposal->fresh()->toArray();$service->run();self::assertSame(11,$db->table('replenishment_migrations')->count());self::assertTrue($db->getSchemaBuilder()->hasTable('replenishment_generations'));
        $service->run(true);self::assertSame(10,$db->table('replenishment_migrations')->count());self::assertSame($before,$proposal->fresh()->toArray());self::assertFalse($db->getSchemaBuilder()->hasTable('replenishment_generations'));self::assertFalse($this->app['db']->connection('other')->getSchemaBuilder()->hasTable('replenishment_policies'));
    }
}
