<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Contracts\PurchaseSource;
use Asisnet\Replenishment\DTO\{InventoryData,ReservationData,PurchaseData,AvailabilityData,ReplenishmentOptions,ReplenishmentPlanData,SafetyStockEvidenceData};
use Asisnet\Replenishment\Services\{AvailabilityCalculator,ReplenishmentCalculator,ReplenishmentPlanner,ReplenishmentQuery,ReplenishmentStore,PackageMigrator};
use Asisnet\Replenishment\Models\{ProductSupplier,PurchaseUnit,SupplierPresentation,ReplenishmentRun,ReplenishmentResult};
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use DateTimeImmutable;
use Orchestra\Testbench\TestCase;

/** Prueba planificación exacta con evidencia ficticia y dos conexiones independientes. */
final class ReplenishmentPlannerTest extends TestCase
{
    /**
     * Registra servicios reales.
     * @param mixed $app Aplicación aislada.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array {return [ReplenishmentServiceProvider::class];}
    /**
     * Declara SQLite separados, sin cargar configuración comercial.
     * @param mixed $app Aplicación.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach(['own','erp'] as $name){$app['config']->set('database.connections.'.$name,['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);}
        $app['config']->set('database.default','erp');$app['config']->set('replenishment.connection','own');$app['config']->set('app.timezone','America/Caracas');
    }
    /**
     * Construye fecha civil operativa.
     * @param string $value Fecha.
     * @return DateTimeImmutable Medianoche.
     */
    private function date(string $value): DateTimeImmutable {return new DateTimeImmutable($value.'T00:00:00-04:00');}
    /**
     * Reutiliza disponibilidad normalizada, incluidas reservas de todas las categorías.
     * @param string|null $stock Existencia.
     * @param array $reserves Reservas explícitas.
     * @param string $asOf Observación.
     * @return AvailabilityData Disponibilidad.
     */
    private function availability(?string $stock='10',array $reserves=[],string $asOf='2026-11-01'): AvailabilityData
    {
        return (new AvailabilityCalculator())->calculate('P','L',$stock===null?null:new InventoryData('P','L',$stock,$this->date($asOf)),$reserves);
    }
    /**
     * Construye informe mensual sintético comparable.
     * @param string $quality Categoría.
     * @param string|null $quantity Cantidad por mes.
     * @return array Informe.
     */
    private function forecast(string $quality='usable',?string $quantity='20'): array
    {
        $rows=[];for($i=0;$i<4;$i++){$d=$this->date('2026-11-01')->modify('+'.$i.' months');$rows[]=['period_start'=>$d->format('Y-m-d'),'period_end'=>$d->modify('+1 month')->format('Y-m-d'),'forecast_quantity'=>$quantity,'quality'=>$quality];}
        return ['product_id'=>'P','location_id'=>'L','base_unit'=>'U','cutoff'=>'2026-11-01T00:00:00-04:00','source_reading_status'=>'complete','origin'=>'synthetic_verified','forecasts'=>$rows];
    }
    /**
     * Proporciona presentación sintética explícita.
     * @return array Factor y vigencia.
     */
    private function presentation(): array {return ['supplier_presentation_id'=>1,'product_id'=>'P','supplier_id'=>'S','revision'=>1,'active'=>true,'relation_active'=>true,'current_revision'=>true,'base_units_per_package'=>'6','minimum_packages'=>'2','package_multiple'=>'2'];}
    /**
     * Produce calendario de pendientes mediante contrato completo.
     * @param array $lines Líneas.
     * @param bool $fail Fallo tardío.
     * @return array Evidencia.
     */
    private function incoming(array $lines=[],bool $fail=false): array
    {
        return (new ReplenishmentCalculator())->incoming(new PlanningPurchaseFixture($lines,$fail),'P','L',$this->date('2026-11-01'),$this->date('2026-12-01'));
    }
    /**
     * Ejecuta el planificador con evidencia base y reemplazos acotados.
     * @param ReplenishmentOptions|null $options Opciones.
     * @param AvailabilityData|null $availability Disponibilidad.
     * @param array|null $forecast Informe alternativo.
     * @param array|null $incoming Pendientes alternativos.
     * @param array|null $presentation Presentación alternativa.
     * @param array|null $lead Plazo alternativo.
     * @param bool $calibrated Aporta evidencia externa ficticia de seguridad.
     * @return ReplenishmentPlanData Resultado.
     */
    private function plan(?ReplenishmentOptions $options=null,?AvailabilityData $availability=null,?array $forecast=null,?array $incoming=null,?array $presentation=null,?array $lead=null,bool $calibrated=true): ReplenishmentPlanData
    {
        $options??=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'));
        $proof=$calibrated?new SafetyStockEvidenceData('P','L','U','5',$options->asOf,$options->targetReceipt->modify('+'.$options->horizon.' months'),'calibración exclusivamente sintética'):null;
        return $this->app->make(ReplenishmentPlanner::class)->plan($availability??$this->availability(),'S','U',$options,$forecast??$this->forecast(),
            $lead??['source'=>'policy_default','quality'=>'explicit_configuration','lead_time_days'=>'7','measure'=>'configured_commercial_lead_time'],
            $incoming??$this->incoming(),$presentation??$this->presentation(),$proof);
    }
    /**
     * La demanda previa no se omite y los paquetes generan excedente trazable.
     * @return void
     */
    public function testEvidenceBasedPlanAndProjectedStock(): void
    {
        $r=$this->plan()->report;self::assertSame('recommended',$r['status']);$q=$r['quantities'];
        self::assertSame('10.000000',$q['available_stock']);self::assertSame('20',$q['consumption_before_receipt']);self::assertSame('-10.000000',$q['projected_stock_at_receipt']);
        self::assertSame('25',$q['target_stock']);self::assertSame('35.000000',$q['required_base_units']);self::assertSame('6',$q['suggested_packages']);self::assertSame('36',$q['suggested_base_units']);self::assertSame('1.000000',$q['rounding_excess']);
        self::assertSame('2026-11-24',$r['dates']['indicative_order']);self::assertNull($q['reorder_point']);self::assertNull($q['projected_coverage_days']);
    }
    /**
     * Las reservas sólo se descuentan en AvailabilityCalculator, preservando saldo negativo.
     * @return void
     */
    public function testReservationsNegativeAndUnknownAvailability(): void
    {
        $res=[];foreach([ReservationData::FIXED,ReservationData::MANUAL,ReservationData::QUOTATION] as $i=>$kind){$res[]=new ReservationData((string)$i,'P','L','4',$kind);}
        $r=$this->plan(availability:$this->availability('10',$res))->report;self::assertSame('-2.000000',$r['quantities']['available_stock']);self::assertSame('-22.000000',$r['quantities']['projected_stock_at_receipt']);
        $res[]=new ReservationData('unknown','P','L',null,ReservationData::MANUAL);$r=$this->plan(availability:$this->availability('10',$res))->report;
        self::assertSame('insufficient_data',$r['status']);self::assertNull($r['quantities']['required_base_units']);
        self::assertNull($this->plan(availability:$this->availability(null))->report['quantities']['available_stock']);
    }
    /**
     * Explorar no recomienda y una demanda insuficiente no genera cantidades.
     * @return void
     */
    public function testForecastCategoriesAndManualDemand(): void
    {
        self::assertSame('simulation',$this->plan(forecast:$this->forecast('exploratory'))->report['status']);
        $r=$this->plan(forecast:$this->forecast('insufficient',null))->report;self::assertSame('insufficient_data',$r['status']);self::assertNull($r['quantities']['required_base_units']);
        $options=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),monthlyDemand:'12.5',consumptionBeforeReceipt:'4',scenarioUnit:'U');
        $r=$this->plan($options,forecast:$this->forecast('insufficient',null))->report;self::assertSame('simulation',$r['status']);self::assertSame('12.5',$r['quantities']['horizon_demand']);self::assertSame('11.500000',$r['quantities']['required_base_units']);
    }
    /**
     * El pendiente desconocido sólo puede sustituirse por hipótesis explícita.
     * @return void
     */
    public function testUnknownIncomingAndExplicitZeroScenario(): void
    {
        $incoming=$this->incoming([],true);$r=$this->plan(incoming:$incoming)->report;self::assertSame('unknown',$r['incoming']['status']);self::assertNull($r['quantities']['inventory_position']);self::assertSame('insufficient_data',$r['status']);
        $options=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),assumeNoIncoming:true);
        $r=$this->plan($options,incoming:$incoming)->report;self::assertSame('simulation',$r['status']);self::assertSame('assumed_absent',$r['incoming']['status']);self::assertTrue($r['assumptions']['assume_no_incoming']);
    }
    /**
     * Se separa posición total de entradas a tiempo, deduplicando identidades.
     * @return void
     */
    public function testIncomingDatesDuplicatesAndLateFailure(): void
    {
        $early=new PurchaseData('1','P','OTHER','L','4',$this->date('2026-11-15'));$late=new PurchaseData('2','P','S','L','9',$this->date('2026-12-03'));
        $incoming=$this->incoming([$early,$early,$late]);self::assertSame('13',$incoming['total']);self::assertSame('4',$incoming['by_target']);self::assertCount(2,$incoming['lines']);
        $r=$this->plan(incoming:$incoming)->report;self::assertSame('23.000000',$r['quantities']['inventory_position']);self::assertSame('-6.000000',$r['quantities']['projected_stock_at_receipt']);
        $unknown=$this->incoming([new PurchaseData('3','P','S','L','8',null)]);self::assertSame('8',$unknown['total']);self::assertNull($unknown['by_target']);
        self::assertSame('insufficient_data',$this->plan(incoming:$unknown)->report['status']);
        self::assertNull($this->incoming([$early],true)['total']);self::assertNull($this->incoming([$early,new PurchaseData('1','P','S','L','7',$this->date('2026-11-15'))])['total']);
    }
    /**
     * Mes incompleto exige consumo explícito; no hay reparto diario implícito.
     * @return void
     */
    public function testTemporalResolutionAndYearBoundary(): void
    {
        $options=new ReplenishmentOptions($this->date('2026-11-09'),$this->date('2026-12-01'),horizon:2);
        $r=$this->plan($options,availability:$this->availability(asOf:'2026-11-09'))->report;self::assertNull($r['quantities']['consumption_before_receipt']);self::assertSame('2027-02-01',$r['horizon_end']);self::assertSame('40',$r['quantities']['horizon_demand']);
        $options=new ReplenishmentOptions($this->date('2026-11-09'),$this->date('2026-12-01'),consumptionBeforeReceipt:'8',scenarioUnit:'U');
        self::assertSame('simulation',$this->plan($options,availability:$this->availability(asOf:'2026-11-09'))->report['status']);
        $options=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-15'));$r=$this->plan($options)->report;self::assertContains('target_not_month_boundary',$r['uncertainty']);self::assertNull($r['quantities']['required_base_units']);
        $forecast=$this->forecast();array_pop($forecast['forecasts']);$options=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2027-02-01'));
        self::assertNull($this->plan($options,forecast:$forecast)->report['quantities']['horizon_demand']);
    }
    /**
     * Seguridad desconocida no se deriva del servicio; toda seguridad manual es escenario.
     * @return void
     */
    public function testSafetyUnknownAndExplicit(): void
    {
        $r=$this->plan(calibrated:false)->report;self::assertNull($r['quantities']['safety_stock']);self::assertNull($r['quantities']['required_base_units']);
        $o=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),safetyUnits:'0',scenarioUnit:'U');$r=$this->plan($o,calibrated:false)->report;
        self::assertSame('simulation',$r['status']);self::assertSame('0',$r['quantities']['safety_stock']);self::assertSame('manual_scenario',$r['safety_source']);
    }
    /**
     * Se distinguen plazo comercial, escenario, primera recepción e insuficiencia de calendario.
     * @return void
     */
    public function testLeadTimeSourcesAndDates(): void
    {
        $r=$this->plan(lead:['source'=>'manual','quality'=>'explicit_configuration','measure'=>'configured_commercial_lead_time','lead_time_days'=>'0'])->report;self::assertSame('2026-12-01',$r['dates']['indicative_order']);
        $r=$this->plan(lead:['lead_time_days'=>null])->report;self::assertNull($r['dates']['indicative_order']);self::assertSame('insufficient_data',$r['status']);
        $r=$this->plan(lead:['source'=>'historical','quality'=>'usable','measure'=>'first_receipt','lead_time_days'=>'3'])->report;self::assertContains('lead_time_only_first_receipt',$r['uncertainty']);
        $o=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),leadTimeDays:40);$r=$this->plan($o)->report;
        self::assertSame('simulation',$r['status']);self::assertTrue($r['dates']['planned_lead_time_insufficient']);self::assertSame('2026-10-22',$r['dates']['indicative_order']);
        $aligned=$this->plan(lead:['source'=>'policy_default','quality'=>'explicit_configuration','measure'=>'configured_commercial_lead_time','lead_time_days'=>'30'])->report;
        self::assertSame('25',$aligned['quantities']['reorder_point']);self::assertSame('5',$aligned['quantities']['minimum_stock']);
        $r=$this->plan(lead:['source'=>'historical','quality'=>'usable','measure'=>'complete_receipt','lead_time_days'=>'3.1'])->report;self::assertSame(4,$r['dates']['lead_time_calendar_days']);
    }
    /**
     * Factores decimales, mínimos y múltiplos se redondean sin pérdida de precisión.
     * @return void
     */
    public function testExactPresentationRoundingAndZeroNeed(): void
    {
        $c=new ReplenishmentCalculator();$p=['base_units_per_package'=>'2.5','minimum_packages'=>'3','package_multiple'=>'2'];
        $r=$c->packages('1',$p);self::assertSame('4',$r['packages']);self::assertSame('10.0',$r['base_units']);self::assertSame('9.0',$r['rounding_excess']);
        $r=$c->packages('10.000001',$p);self::assertSame('6',$r['packages']);self::assertSame('4.999999',$r['rounding_excess']);
        $r=$c->packages('0',$p);self::assertSame('0',$r['packages']);
        $r=$this->plan(availability:$this->availability('100'))->report;self::assertSame('not_required',$r['status']);self::assertSame('0',$r['quantities']['suggested_packages']);
        $p=$this->presentation();$p['current_revision']=false;self::assertSame('insufficient_data',$this->plan(presentation:$p)->report['status']);
    }
    /**
     * Un pronóstico generado con corte futuro no puede explicar una decisión anterior.
     * @return void
     */
    public function testFutureForecastCutoffIsRejected(): void
    {
        $forecast=$this->forecast();$forecast['cutoff']='2026-12-01T00:00:00-04:00';
        $this->expectException(\InvalidArgumentException::class);$this->plan(forecast:$forecast);
    }
    /**
     * Identidades y unidades incompatibles no se corrigen mediante supuestos.
     * @return void
     */
    public function testUnitMismatchIsRejected(): void
    {
        $o=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),monthlyDemand:'3',scenarioUnit:'OTHER');
        $this->expectException(\InvalidArgumentException::class);$this->plan($o);
    }
    /**
     * La posición futura no usa una observación antigua y una fecha de entrada ausente no se elimina por hipótesis.
     * @return void
     */
    public function testStaleInventoryAndKnownIncomingWithoutCalendar(): void
    {
        $r=$this->plan(availability:$this->availability(asOf:'2026-10-31'))->report;
        self::assertContains('inventory_observation_date_mismatch',$r['uncertainty']);self::assertNull($r['quantities']['projected_stock_at_receipt']);
        $o=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),assumeNoIncoming:true);
        $r=$this->plan($o,incoming:$this->incoming([new PurchaseData('1','P','S','L','5',null)]))->report;
        self::assertSame('certified_positive',$r['incoming']['status']);self::assertSame('insufficient_data',$r['status']);self::assertNull($r['quantities']['required_base_units']);self::assertArrayNotHasKey('assume_no_incoming',$r['assumptions']);
        $o=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),safetyUnits:'0',scenarioUnit:'U');
        $r=$this->plan($o,availability:$this->availability('100'))->report;self::assertSame('simulation',$r['status']);self::assertSame('0',$r['quantities']['suggested_packages']);
    }
    /**
     * La calibración debe cubrir el horizonte completo; una etiqueta ajena no habilita recomendación.
     * @return void
     */
    public function testSafetyCalibrationScopeIsRequired(): void
    {
        $proof=new SafetyStockEvidenceData('P','OTHER','U','5',$this->date('2026-11-01'),$this->date('2027-01-01'),'referencia sintética');
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ReplenishmentPlanner::class)->plan($this->availability(),'S','U',new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01')),$this->forecast(),[], $this->incoming(),$this->presentation(),$proof);
    }
    /**
     * El historial conserva doce decimales y las columnas existentes reciben redondeo declarado; una revisión nueva invalida la anterior.
     * @return void
     */
    public function testExactAuditAndPresentationRevision(): void
    {
        $this->app->make(PackageMigrator::class)->run();$relation=ProductSupplier::create(['product_id'=>'P','supplier_id'=>'S']);$unit=PurchaseUnit::create(['code'=>'BOX','name'=>'Caja']);
        $old=SupplierPresentation::create(['product_supplier_id'=>$relation->id,'purchase_unit_id'=>$unit->id,'revision'=>1,'base_units_per_package'=>'2.5','minimum_packages'=>'0','package_multiple'=>'1']);
        $query=$this->app->make(ReplenishmentQuery::class);$snapshot=$query->presentation($old);
        $plan=$this->plan(forecast:$this->forecast(quantity:'20.123456789123'),presentation:$snapshot);
        $ids=$this->app->make(ReplenishmentStore::class)->persist($plan);$saved=ReplenishmentResult::findOrFail($ids['result_id']);
        self::assertSame('20.123457',$saved->lead_time_demand);self::assertSame('20.123456789123',json_decode($saved->explanation,true)['quantities']['consumption_before_receipt']);
        $next=SupplierPresentation::create(['product_supplier_id'=>$relation->id,'purchase_unit_id'=>$unit->id,'revision'=>2,'base_units_per_package'=>'5','minimum_packages'=>'0','package_multiple'=>'1']);
        self::assertFalse($query->presentation($old)['current_revision']);self::assertTrue($query->presentation($next)['current_revision']);
        self::assertSame('2.500000',$saved->fresh()->presentation_snapshot['base_units_per_package']);
        $this->expectException(\InvalidArgumentException::class);$this->app->make(ReplenishmentStore::class)->persist($plan);
    }
    /**
     * Conserva fotografía, categoría simulada y dos filas atómicas en conexión propia.
     * @return void
     */
    public function testPersistenceSnapshotImmutabilityAndRollback(): void
    {
        $erp=$this->app['db']->connection('erp');$erp->statement('CREATE TABLE marker (value TEXT)');$erp->table('marker')->insert(['value'=>'intacto']);$erp->enableQueryLog();
        $this->app->make(PackageMigrator::class)->run();
        $relation=ProductSupplier::create(['product_id'=>'P','supplier_id'=>'S']);$unit=PurchaseUnit::create(['code'=>'BOX','name'=>'Caja ficticia']);
        $model=SupplierPresentation::create(['product_supplier_id'=>$relation->id,'purchase_unit_id'=>$unit->id,'base_units_per_package'=>'6','minimum_packages'=>'2','package_multiple'=>'2']);
        $snapshot=$this->app->make(ReplenishmentQuery::class)->presentation($model);self::assertTrue($snapshot['current_revision']);
        $options=new ReplenishmentOptions($this->date('2026-11-01'),$this->date('2026-12-01'),safetyUnits:'0',scenarioUnit:'U');$plan=$this->plan($options,presentation:$snapshot);
        $store=$this->app->make(ReplenishmentStore::class);$ids=$store->persist($plan);$result=ReplenishmentResult::findOrFail($ids['result_id']);
        self::assertSame('simulation',$result->status);self::assertSame('6.000000',$result->presentation_snapshot['base_units_per_package']);self::assertSame('own',$result->getConnectionName());self::assertSame($ids['run_id'],$result->run->id);
        try{$result->status='recommended';$result->save();self::fail('Debe conservar el histórico.');}catch(\LogicException $error){self::assertSame('simulation',$result->fresh()->status);}
        $store->persist($plan);self::assertSame(2,ReplenishmentRun::count());self::assertSame(2,ReplenishmentResult::count());
        ReplenishmentResult::created(function():void{throw new \RuntimeException('fallo posterior a insertar resultado');});
        try{$store->persist($plan);self::fail('Debe revertir ambas filas.');}catch(\RuntimeException $error){self::assertSame(2,ReplenishmentRun::count());self::assertSame(2,ReplenishmentResult::count());}
        self::assertSame([],$erp->getQueryLog());self::assertSame('intacto',$erp->table('marker')->value('value'));
        $model->active=false;$model->save();$this->expectException(\InvalidArgumentException::class);$store->persist($plan);
    }
}

/** Fuente ficticia que certifica el lote sólo al terminar su iteración. */
final class PlanningPurchaseFixture implements PurchaseSource
{
    /**
     * Recibe pendientes sintéticos.
     * @param array $rows Líneas.
     * @param bool $fail Fallo tardío.
     */
    public function __construct(private array $rows,private bool $fail=false) {}
    /**
     * Emite líneas y fallo controlado.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @return iterable Pendientes.
     * @throws \RuntimeException Si se solicita fallo tardío.
     */
    public function pendingFor(string $productId,string $locationId): iterable {yield from $this->rows;if($this->fail){throw new \RuntimeException('pendiente no certificado');}}
}
