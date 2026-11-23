<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Asisnet\Replenishment\Services\{DocumentExclusions,StatisticsCutoff};
use Orchestra\Testbench\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/** Verifica configuración y extensión ERP exclusivamente sobre dos SQLite efímeras. */
final class StatisticsConfiguration733Test extends TestCase
{
    /** Registra publicación real sin ejecutar migraciones. @param mixed $app Aplicación temporal. @return array Proveedores. */
    protected function getPackageProviders($app): array {return [ReplenishmentServiceProvider::class];}
    /** Separa destino propio y maestro externo ficticio. @param mixed $app Aplicación temporal. @return void */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default','testing');foreach(['testing','external'] as $name){$app['config']->set('database.connections.'.$name,['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);}
        $app['config']->set('replenishment.statistics_cutoff',['enabled'=>true,'connection'=>'external','table'=>'products','column'=>'cutoff']);
    }
    /** Crea únicamente el maestro ficticio. @return void */
    protected function setUp(): void {parent::setUp();$this->app['db']->connection('external')->getSchemaBuilder()->create('products',function($table):void{$table->string('code')->primary();});}
    /** Verifica SQL parametrizado y NOT de la disyunción, conservando documentos NULL para diagnóstico. @return void */
    public function testExclusionsBindAndCombine(): void
    {
        $db=$this->app['db']->connection('external');$db->getSchemaBuilder()->create('movements',function($table):void{$table->string('document')->nullable();});foreach(['3101','0007','08','09','10',null] as $code){$db->table('movements')->insert(['document'=>$code]);}
        $rules=new DocumentExclusions([['operator'=>'like','value'=>'31%'],['operator'=>'=','value'=>'0007'],['operator'=>'in','value'=>['08','09']]]);$query=$rules->apply($db->table('movements'),'document');self::assertSame(['31%','0007','08','09'],$query->getBindings());self::assertSame(['10',null],$query->pluck('document')->all());self::assertStringNotContainsString('31%',$query->toSql());self::assertNotSame($rules->fingerprint(),(new DocumentExclusions())->fingerprint());
    }
    /** Rechaza reglas inválidas sin omitirlas silenciosamente. @return void */
    public function testInvalidRules(): void
    {
        foreach([[['operator'=>'in','value'=>[]]],[['operator'=>'like','value'=>31]],[['operator'=>'raw','value'=>'1=1']],[['operator'=>'=','value'=>['01']]],[['operator'=>'in','value'=>['01',2]]],[['operator'=>'=','value'=>'']]] as $rules){try{new DocumentExclusions($rules);self::fail('Se aceptó una exclusión inválida.');}catch(InvalidArgumentException $error){self::assertNotSame('',$error->getMessage());}}
    }
    /** El modo deshabilitado no abre ni valida un destino inexistente. @return void */
    public function testDisabledDoesNotReadColumn(): void
    {
        $this->app['config']->set('replenishment.statistics_cutoff',['enabled'=>false]);self::assertNull($this->app->make(StatisticsCutoff::class)->forProduct('P','code'));
    }
    /** Agrega DATE NULL al destino explícito; una segunda ejecución no adopta ni borra la columna. @return void */
    public function testInstallationAndProtectedRollback(): void
    {
        $service=$this->app->make(StatisticsCutoff::class);$service->install();$service->install();$db=$this->app['db']->connection('external');self::assertTrue($db->getSchemaBuilder()->hasColumn('products','cutoff'));self::assertFalse($this->app['db']->connection('testing')->getSchemaBuilder()->hasTable('products'));$db->table('products')->insert(['code'=>'P','cutoff'=>'2026-08-01']);self::assertSame('2026-08-01',$service->forProduct('P','code'));
        $migration=require __DIR__.'/../database/erp-published/2026_11_18_000001_add_replenishment_statistics_cutoff.php';$this->app['config']->set('replenishment.statistics_cutoff.table','different_target');try{$migration->down();self::fail('El rollback no debe borrar columnas.');}catch(LogicException $error){self::assertStringContainsString('manual',$error->getMessage());}self::assertSame('2026-08-01',$db->table('products')->value('cutoff'));
    }
    /** Una columna existente incompatible se conserva y produce error. @return void */
    public function testIncompatibleExistingColumn(): void
    {
        $this->app['db']->connection('external')->getSchemaBuilder()->table('products',function($table):void{$table->string('cutoff')->nullable();});$this->expectException(LogicException::class);$this->app->make(StatisticsCutoff::class)->install();
    }
    /** La falta de instalación no se interpreta como ausencia de corte. @return void */
    public function testMissingColumn(): void {$this->expectException(LogicException::class);$this->expectExceptionMessage('Instalación pendiente');$this->app->make(StatisticsCutoff::class)->forProduct('P','code');}
    /** El corte inclusivo reduce a 108 días sin alterar el intervalo solicitado posterior. @return void */
    public function testEffectiveWindow(): void
    {
        $service=$this->app->make(StatisticsCutoff::class);$zone=new \DateTimeZone('America/Caracas');$from=new DateTimeImmutable('2025-11-18',$zone);$start=$service->effectiveStart($from,'2026-08-01','America/Caracas');self::assertSame('2026-08-02',$start->format('Y-m-d'));self::assertSame(108,$start->diff(new DateTimeImmutable('2026-11-18',$zone))->days);self::assertSame($from,$service->effectiveStart($from,null,'America/Caracas'));
    }
    /** El archivo ERP se publica aparte, sin registrarse en el migrador propio. @return void */
    public function testSeparatePublication(): void
    {
        $paths=\Illuminate\Support\ServiceProvider::pathsToPublish(ReplenishmentServiceProvider::class,'replenishment-statistics-cutoff-migration');self::assertCount(1,$paths);self::assertStringEndsWith('migrations/replenishment-erp',str_replace('\\','/',array_values($paths)[0]));self::assertStringNotContainsString('erp-published',file_get_contents(__DIR__.'/../src/Services/PackageMigrator.php'));
    }
}
