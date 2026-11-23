<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Contracts\{ProductSource,SupplierSource};
use Asisnet\Replenishment\DTO\{ProductData,SupplierData,InventoryData,ReplenishmentOptions};
use Asisnet\Replenishment\Services\{CommercialConfiguration,PackageMigrator,ReplenishmentQuery,ReplenishmentPlanner,AvailabilityCalculator};
use Asisnet\Replenishment\Models\{PurchaseUnit,ProductSupplier,SupplierPresentation,Policy,ReplenishmentResult};
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use DateTimeImmutable;
use Orchestra\Testbench\TestCase;

/** Verifica administración comercial aislada, revisiones y consumo por el planificador. */
final class CommercialConfigurationTest extends TestCase
{
    /**
     * Registra el paquete.
     * @param mixed $app Contenedor.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array {return [ReplenishmentServiceProvider::class];}
    /**
     * Configura SQLite y maestros ficticios sin bases externas.
     * @param mixed $app Aplicación.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach(['own','erp'] as $name){$app['config']->set('database.connections.'.$name,['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);}
        $app['config']->set('database.default','erp');$app['config']->set('replenishment.connection','own');
        $app['config']->set('replenishment.adapters.products',ConfigurationProductFixture::class);$app['config']->set('replenishment.adapters.suppliers',ConfigurationSupplierFixture::class);
    }
    /**
     * Prepara exclusivamente el esquema propio temporal.
     * @return void
     */
    protected function setUp(): void {parent::setUp();$this->app->make(PackageMigrator::class)->run();}
    /** Rechaza consentimiento ausente, actor recibido del formulario y autorización anónima. @return void */
    public function testContinuityRequiresAuthenticatedConsentAndImmutableEvidence(): void
    {
        $service = $this->service();
        foreach ([[['continuity'=>'assumed'],'usuario'], [['continuity'=>'assumed','continuity_consent'=>true],null],
            [['continuity'=>'assumed','continuity_consent'=>true,'authorized_by'=>'externo'],'usuario']] as [$settings,$actor]) {
            try {
                $service->create('policies',['name'=>'Inválida','demand_settings'=>$settings],$actor);
                self::fail('Se aceptó continuidad sin un consentimiento válido.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(0,Policy::count());
            }
        }
        $policy = $service->create('policies',['name'=>'Prueba','demand_settings'=>['continuity'=>'assumed','continuity_consent'=>true]],'creador');
        $service->update('policies',$policy->id,['description'=>'Descripción editada'],'editor');
        $service->update('policies',$policy->id,['demand_settings'=>['continuity'=>'strict']],'editor');
        $event = \Asisnet\Replenishment\Models\PolicyEvent::where('policy_id',$policy->id)->orderByDesc('id')->firstOrFail();
        self::assertSame('creador',$event->payload['before']['continuity_authorized_by']);
        self::assertNull($event->payload['after']['continuity_authorized_by']);
        self::assertSame('editor',$event->actor_id);
    }

    /**
     * Obtiene el administrador real.
     * @return CommercialConfiguration Servicio.
     */
    private function service(): CommercialConfiguration {return $this->app->make(CommercialConfiguration::class);}
    /**
     * Crea relación y dos presentaciones de factores distintos mediante API reusable.
     * @return array Configuración.
     */
    private function fixtures(): array
    {
        $s=$this->service();$a=$s->create('suppliers',['product_id'=>'0001','supplier_id'=>'0002','manual_lead_time_days'=>0]);
        $b=$s->create('suppliers',['product_id'=>'0001','supplier_id'=>'0003','manual_lead_time_days'=>7]);
        $box=$s->create('units',['code'=>'BOX','name'=>'Caja']);$bulk=$s->create('units',['code'=>'BULK','name'=>'Bulto']);
        $p=$s->create('presentations',['product_supplier_id'=>$a->id,'purchase_unit_id'=>$box->id,'base_units_per_package'=>'12','minimum_packages'=>'0','package_multiple'=>'1']);
        $q=$s->create('presentations',['product_supplier_id'=>$a->id,'purchase_unit_id'=>$bulk->id,'base_units_per_package'=>'24','minimum_packages'=>'2','package_multiple'=>'2']);
        return [$a,$b,$box,$bulk,$p,$q];
    }
    /**
     * Comprueba altas, consultas, texto, estados, ceros iniciales y NULL explícito.
     * @return void
     */
    public function testCrudAndExplicitLeadTimes(): void
    {
        [$a,$b,$box]=$this->fixtures();$s=$this->service();self::assertSame('0001',$a->product_id);self::assertSame('0002',$a->supplier_id);self::assertSame(0,$a->manual_lead_time_days);
        self::assertCount(2,$s->list('suppliers'));self::assertCount(1,$s->list('suppliers',$a->id,1));self::assertSame(7,$s->find('suppliers',$b->id)->manual_lead_time_days);
        $unit=$s->update('units',$box->id,['name'=>'Caja explícita','description'=>'Doce unidades','active'=>false]);self::assertFalse($unit->active);self::assertSame('Caja explícita',$unit->name);
        $a=$s->update('suppliers',$a->id,['manual_lead_time_days'=>null,'is_preferred'=>true,'active'=>false,'notes'=>'Revisión comercial']);self::assertNull($a->manual_lead_time_days);self::assertTrue($a->is_preferred);self::assertFalse($a->active);
    }
    /**
     * Rechaza identidades inexistentes sin insertar maestros propios ni asociaciones parciales.
     * @return void
     */
    public function testMissingExternalIdentities(): void
    {
        foreach([['product_id'=>'missing','supplier_id'=>'0002'],['product_id'=>'0001','supplier_id'=>'missing'],['product_id'=>1,'supplier_id'=>'0002']] as $input){
            try{$this->service()->create('suppliers',$input);self::fail('Debe rechazar la identidad.');}catch(\InvalidArgumentException $error){self::assertSame(0,ProductSupplier::count());}}
    }
    /**
     * La unicidad de producto/proveedor y unidad comercial se conserva en base de datos.
     * @return void
     */
    public function testDuplicateRelationshipsAndUnits(): void
    {
        $this->fixtures();foreach([['suppliers',['product_id'=>'0001','supplier_id'=>'0002']],['units',['code'=>'BOX','name'=>'Duplicada']]] as [$resource,$data]){
            try{$this->service()->create($resource,$data);self::fail('Debe rechazar duplicados.');}catch(\Illuminate\Database\QueryException $error){self::assertSame(2,ProductSupplier::count());self::assertSame(2,PurchaseUnit::count());}}
    }
    /**
     * No se aceptan factores inválidos, floats ni revisión impuesta por el cliente.
     * @return void
     */
    public function testInvalidCommercialValues(): void
    {
        [, , , ,$p]=$this->fixtures();foreach([['base_units_per_package'=>'0'],['package_multiple'=>'0'],['minimum_packages'=>'-1'],['base_units_per_package'=>2.5],['revision'=>99],['active'=>'false']] as $input){
            try{$this->service()->update('presentations',$p->id,$input);self::fail('Debe rechazar el valor.');}catch(\InvalidArgumentException $error){self::assertSame(2,SupplierPresentation::count());}}
        self::assertSame('12.000000',$p->fresh()->base_units_per_package);
    }
    /**
     * Los cambios de conversión crean revisión de la misma unidad, sin seleccionar otra presentación.
     * @return void
     */
    public function testRevisionsAndStaleUpdates(): void
    {
        [$a,$b,$box,$bulk,$p,$q]=$this->fixtures();$s=$this->service();$next=$s->update('presentations',$p->id,['base_units_per_package'=>'12.125001','minimum_packages'=>'0.5','package_multiple'=>'0.25']);
        self::assertSame(2,$next->revision);self::assertNotSame($p->id,$next->id);self::assertSame('12.000000',$p->fresh()->base_units_per_package);self::assertTrue($s->isCurrent($q));self::assertFalse($s->isCurrent($p));self::assertTrue($s->isCurrent($next));
        self::assertSame('12.125001',$next->base_units_per_package);$s->update('presentations',$next->id,['active'=>false]);self::assertTrue($s->isCurrent($next));self::assertFalse($s->isCurrent($p));
        $this->expectException(\LogicException::class);$s->update('presentations',$p->id,['base_units_per_package'=>'30']);
    }
    /**
     * Políticas incompletas permanecen inactivas; no se inventan parámetros comerciales.
     * @return void
     */
    public function testIncompleteAndCompletePolicies(): void
    {
        $s=$this->service();$p=$s->create('policies',['name'=>'Explícita']);self::assertFalse($p->active);self::assertNull($p->service_level);
        try{$s->update('policies',$p->id,['active'=>true]);self::fail('Debe exigir parámetros.');}catch(\InvalidArgumentException $error){self::assertFalse($p->fresh()->active);}
        $p=$s->update('policies',$p->id,['analysis_days'=>365,'target_coverage_days'=>30,'service_level'=>'0.950000','default_lead_time_days'=>0,'minimum_history_days'=>90,'seasonal_minimum_years'=>3,'forecast_horizon_days'=>60,'active'=>true]);
        self::assertTrue($p->active);self::assertSame('0.950000',$p->service_level);self::assertFalse($s->update('policies',$p->id,['active'=>false])->active);
    }
    /**
     * Una excepción posterior al INSERT revierte la revisión sin afectar la conexión ERP.
     * @return void
     */
    public function testAtomicRevisionAndConnectionIsolation(): void
    {
        [, , , ,$p]=$this->fixtures();$erp=$this->app['db']->connection('erp');$erp->enableQueryLog();
        SupplierPresentation::created(function():void{throw new \RuntimeException('fallo sintético');});
        try{$this->service()->update('presentations',$p->id,['base_units_per_package'=>'30']);self::fail('Debe revertir.');}catch(\RuntimeException $error){self::assertSame(2,SupplierPresentation::count());}
        self::assertTrue($this->service()->isCurrent($p));self::assertSame([],$erp->getQueryLog());self::assertSame('own',$p->getConnectionName());
    }
    /**
     * El planificador consume la revisión elegida; históricos conservan fotografía al cambiarla.
     * @return void
     */
    public function testPlannerIntegrationAndHistoricalSnapshot(): void
    {
        [$a,$b,$box,$bulk,$p,$q]=$this->fixtures();$date=new DateTimeImmutable('2026-12-01T00:00:00-04:00');
        $availability=(new AvailabilityCalculator())->calculate('0001','L',new InventoryData('0001','L','0',$date),[]);
        $options=new ReplenishmentOptions($date,$date,monthlyDemand:'25',safetyUnits:'0',leadTimeDays:0,scenarioUnit:'U');
        $snapshot=$this->app->make(ReplenishmentQuery::class)->presentation($p);
        $plan=$this->app->make(ReplenishmentPlanner::class)->plan($availability,'0002','U',$options,null,[],['status'=>'certified_absent','total'=>'0','by_target'=>'0','calendar_known'=>true,'lines'=>[]],$snapshot);
        self::assertSame('simulation',$plan->report['status']);self::assertSame('0002',$plan->report['supplier_id']);self::assertSame($p->id,$plan->report['presentation']['supplier_presentation_id']);self::assertSame('36.000000000000',$plan->report['quantities']['suggested_base_units']);
        $ids=$this->app->make(\Asisnet\Replenishment\Services\ReplenishmentStore::class)->persist($plan);
        $next=$this->service()->update('presentations',$p->id,['base_units_per_package'=>'10']);$saved=ReplenishmentResult::find($ids['result_id']);self::assertSame('12.000000',$saved->presentation_snapshot['base_units_per_package']);self::assertSame(1,$saved->presentation_snapshot['revision']);self::assertSame(2,$next->revision);
        self::assertFalse(method_exists($this->service(),'delete'));
    }
}
/** Maestro de productos ficticio; las identidades son texto exacto. */
final class ConfigurationProductFixture implements ProductSource
{
    /**
     * Consulta producto conocido.
     * @param string $productId Identidad.
     * @return ProductData|null Producto o ausencia.
     */
    public function find(string $productId): ?ProductData {return $productId==='0001'?new ProductData('0001','Producto ficticio','U'):null;}
}
/** Maestro de proveedores ficticios, independiente de historial de compras. */
final class ConfigurationSupplierFixture implements SupplierSource
{
    /**
     * Consulta los dos proveedores de prueba.
     * @param string $supplierId Identidad.
     * @return SupplierData|null Proveedor o ausencia.
     */
    public function find(string $supplierId): ?SupplierData {return in_array($supplierId,['0002','0003'],true)?new SupplierData($supplierId,'Proveedor ficticio'):null;}
}
