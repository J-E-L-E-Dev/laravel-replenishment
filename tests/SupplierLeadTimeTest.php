<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Contracts\PurchaseHistorySource;
use Asisnet\Replenishment\DTO\{InventoryMovementData,PurchaseOrderData,PurchaseOrderLineData,PurchaseReceiptData,PurchaseReconciliationData,LeadTimeCoverageData,SupplierLeadTimeOptions,SupplierLeadTimeData};
use Asisnet\Replenishment\Services\{SupplierLeadTimeAnalyzer,SupplierLeadTimeResolver,SupplierLeadTimeStore,PurchaseReconciler,PackageMigrator,PersistenceConnection};
use Asisnet\Replenishment\Models\{ProductSupplier,SupplierMetric,Policy};
use Asisnet\Replenishment\Database\Migrations\{CreateReplenishmentTables,AddObservedSales,AddSupplierLeadTimeEvidence};
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use DateTimeImmutable;
use Orchestra\Testbench\TestCase;

/** Verifica plazos sintéticos, fuentes explícitas y persistencia aislada sin servicios externos. */
final class SupplierLeadTimeTest extends TestCase
{
    /**
     * Registra el proveedor real.
     * @param mixed $app Aplicación aislada.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array {return [ReplenishmentServiceProvider::class];}
    /**
     * Configura dos SQLite independientes.
     * @param mixed $app Aplicación.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach(['own','erp'] as $name){$app['config']->set('database.connections.'.$name,['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);}
        $app['config']->set('database.default','erp');$app['config']->set('replenishment.connection','own');$app['config']->set('app.timezone','America/Caracas');
    }
    /**
     * Construye medianoche civil.
     * @param string $date Fecha.
     * @return DateTimeImmutable Fecha operativa.
     */
    private function date(string $date): DateTimeImmutable {return new DateTimeImmutable($date.'T00:00:00-04:00');}
    /**
     * Concilia órdenes ficticias mediante las reglas aprobadas, sin duplicarlas.
     * @param string $number Identidad.
     * @param array $receipts Pares fecha y cantidad y posibles reemplazos de ámbito.
     * @param string|null $ordered Emisión o desconocida.
     * @param string $location Destino.
     * @param array|null $lines Líneas alternativas.
     * @param string|null $expected Estimación explícita.
     * @return PurchaseReconciliationData Conciliación original.
     */
    private function row(string $number='1',array $receipts=[['2026-01-03','10']],?string $ordered='2026-01-01',string $location='L',?array $lines=null,?string $expected=null): PurchaseReconciliationData
    {
        $order=new PurchaseOrderData('D',$number,'S',$location,$ordered===null?null:$this->date($ordered),$expected===null?null:$this->date($expected),'unknown',
            $lines??[new PurchaseOrderLineData('D',$number,'1','P','S','10','U','U',null)]);
        $movements=[];
        foreach($receipts as $i=>$r){$m=new InventoryMovementData($number.'-'.$i,$r[3]??'P',$location,'R',(string)$i,'entry',$this->date($r[0]),$r[1],$r[1],$r[1],'0','U','U',null);
            $movements[]=new PurchaseReceiptData($m,'receipt',$r[2]??'S','referencia',$r[4]??'D',$number);}
        return (new PurchaseReconciler())->reconcile($order,'P',$movements,$this->date('2026-02-01'));
    }
    /**
     * Ejecuta evidencia sintética con o sin atestación externa de completitud.
     * @param array $rows Conciliaciones.
     * @param bool $proof Cobertura ficticia explícita para pruebas.
     * @param SupplierLeadTimeOptions|null $options Criterios.
     * @param string $location Destino.
     * @param bool $fail Simula fallo tardío.
     * @param string $evaluated Fecha de evaluación.
     * @return SupplierLeadTimeData Diagnóstico.
     */
    private function analyze(array $rows,bool $proof=false,?SupplierLeadTimeOptions $options=null,string $location='L',bool $fail=false,string $evaluated='2026-02-01'): SupplierLeadTimeData
    {
        $coverage=[];if($proof){foreach($rows as $r){$coverage[]=new LeadTimeCoverageData('D',$r->order->number,'P','S',$location,$this->date('2025-01-01'),$this->date('2026-02-01'),'cobertura sintética completa',$r->observedCompletionAt,$r->observedCompletionAt===null?null:'recepción completa sintética');}}
        return $this->app->make(SupplierLeadTimeAnalyzer::class)->analyze(new LeadTimeFixtureSource($rows,$fail),'P','S',$location,$this->date('2025-01-01'),$this->date('2026-02-01'),'America/Caracas',$options??new SupplierLeadTimeOptions(),$coverage,$this->date($evaluated));
    }
    /**
     * La cobertura observada no certifica completitud, cierre ni cumplimiento.
     * @return void
     */
    public function testObservedReceiptIsNotAutomaticCompletion(): void
    {
        $r=$this->analyze([$this->row()]);$e=$r->observations[0]->evidence;
        self::assertSame(2,$e['first_observed_days']);self::assertTrue($e['documentary_link_verified']);self::assertTrue($e['observed_quantity_coverage']);
        self::assertNull($e['complete_receipt_days']);self::assertFalse($e['first_receipt_certified']);self::assertSame('exploratory',$r->summary['observed_first_receipt']['quality']);
        foreach(['quantity_fulfillment','date_fulfillment','commercial_closure','confidence'] as $key){self::assertNull($r->summary[$key]);}
    }
    /**
     * Separa primera, parciales y completa sólo acreditada externamente.
     * @return void
     */
    public function testPartialAndMultipleReceipts(): void
    {
        $r=$this->analyze([$this->row('1',[['2026-01-03','3'],['2026-01-05','7']]),$this->row('2',[['2026-01-03','3']])],true);
        self::assertSame(2,$r->summary['partial_receipts_observed']);self::assertSame(2,$r->observations[0]->evidence['first_observed_days']);
        self::assertSame(4,$r->observations[0]->evidence['complete_receipt_days']);self::assertNull($r->observations[1]->evidence['complete_receipt_days']);
        self::assertSame(2,$r->observations[0]->evidence['verified_link_count']);
        $same=$this->analyze([$this->row('3',[['2026-01-03','3'],['2026-01-03','7']])]);self::assertSame(1,$same->summary['partial_receipts_observed']);
    }
    /**
     * Conserva cero real, cruce de año y diferencia firmada frente a estimación.
     * @return void
     */
    public function testCalendarDaysAndExpectedDates(): void
    {
        $r=$this->analyze([$this->row('1',[['2026-01-01','10']],expected:'2026-01-03'),$this->row('2',[['2026-01-02','10']],'2025-12-31',expected:'2026-01-01')]);
        self::assertSame(0,$r->observations[0]->evidence['first_observed_days']);self::assertSame(-2,$r->observations[0]->evidence['expected_to_first_days']);
        self::assertSame(2,$r->observations[1]->evidence['first_observed_days']);self::assertSame(1,$r->observations[1]->evidence['expected_to_first_days']);
    }
    /**
     * Rechaza ausencia, referencias falsas, ámbitos distintos y fechas incompatibles.
     * @return void
     */
    public function testRejectedEvidenceNeverBecomesZero(): void
    {
        $rows=[$this->row('1',[]),$this->row('2',[['2026-01-03','10','S','P','OTHER']]),$this->row('3',[['2026-01-03','10','OTHER']]),
            $this->row('4',[['2026-01-03','10','S','Q']]),$this->row('5',ordered:null),$this->row('6',[['2025-12-31','10']])];
        $r=$this->analyze($rows);self::assertSame(6,$r->summary['orders_excluded']);self::assertNull($r->summary['observed_first_receipt']['average_days']);
        foreach($r->observations as $o){self::assertNull($o->evidence['first_observed_days']);self::assertNotEmpty($o->evidence['exclusions']);}
    }
    /**
     * Dos líneas distintas conservan orden/producto; identidades duplicadas invalidan la orden.
     * @return void
     */
    public function testMultipleLinesAndAmbiguity(): void
    {
        $a=new PurchaseOrderLineData('D','1','1','P','S','4','U','U',null);$b=new PurchaseOrderLineData('D','1','2','P','S','6','U','U',null);
        $r=$this->analyze([$this->row(lines:[$a,$b])]);self::assertSame(1,$r->summary['orders_accepted']);self::assertSame(2,$r->observations[0]->evidence['first_observed_days']);
        $r=$this->analyze([$this->row(lines:[$a,$a])]);self::assertSame(1,$r->summary['orders_excluded']);
    }
    /**
     * Calcula desviación poblacional exacta y suficiencia configurable sin confianza arbitraria.
     * @return void
     */
    public function testStatisticsSufficiencyAndFreshness(): void
    {
        $rows=[$this->row('1'),$this->row('2',[['2026-01-05','10']]),$this->row('3',[['2026-01-07','10']])];
        $r=$this->analyze($rows,true);$s=$r->summary['verified_complete_receipt'];
        self::assertSame('usable',$s['quality']);self::assertSame('2',$s['minimum_days']);self::assertSame('4.000000',$s['average_days']);self::assertSame('6',$s['maximum_days']);self::assertSame('1.632993',$s['deviation_days']);self::assertNull($s['confidence']);
        self::assertSame('exploratory',$this->analyze([$rows[0]],true)->summary['verified_complete_receipt']['quality']);
        self::assertNull($this->analyze([$rows[0]],true)->summary['verified_complete_receipt']['deviation_days']);
        self::assertSame('exploratory',$this->analyze($rows,true,evaluated:'2028-02-01')->summary['verified_complete_receipt']['quality']);
        self::assertSame('exploratory',$this->analyze([...$rows,$this->row('4',[])],true,new SupplierLeadTimeOptions(minimumCoverage:'0.80'))->summary['verified_complete_receipt']['quality']);
    }
    /**
     * Fallos y duplicados no publican agregados parciales.
     * @return void
     */
    public function testIncompleteReadsAndDuplicateOrders(): void
    {
        foreach([$this->analyze([$this->row()],fail:true),$this->analyze([$this->row(),$this->row()])] as $r){self::assertSame('incomplete',$r->summary['reading_status']);self::assertNull($r->summary['orders_evaluated']);self::assertNull($r->summary['observed_first_receipt']['average_days']);self::assertSame([],$r->observations);}
    }
    /**
     * Prioriza manual vigente; el historial exploratorio no se convierte en plazo operativo.
     * @return void
     */
    public function testSourcePrecedenceManualZeroPolicyAndUnknown(): void
    {
        $resolver=new SupplierLeadTimeResolver();$options=new SupplierLeadTimeOptions();$history=$this->analyze([$this->row('1'),$this->row('2'),$this->row('3')],true);
        $relation=new ProductSupplier(['product_id'=>'P','supplier_id'=>'S','active'=>true,'manual_lead_time_days'=>0]);
        $r=$resolver->resolve($history,$options,$relation,defaultDays:8);self::assertSame('manual',$r->resolution['source']);self::assertSame('0',$r->resolution['lead_time_days']);self::assertNull($r->resolution['selected_source_sample_size']);
        $relation->active=false;$r=$resolver->resolve($history,$options,$relation,defaultDays:8);self::assertSame('historical',$r->resolution['source']);self::assertSame('product_supplier_location',$r->resolution['source_scope']);
        $poor=$this->analyze([$this->row()]);$r=$resolver->resolve($poor,$options,defaultDays:0);self::assertSame('policy_default',$r->resolution['source']);self::assertSame('0',$r->resolution['lead_time_days']);
        $policy=new Policy(['active'=>true,'default_lead_time_days'=>9]);self::assertSame('9',$resolver->resolve($poor,$options,policy:$policy,defaultDays:8)->resolution['lead_time_days']);
        self::assertNull($resolver->resolve($poor,$options)->resolution['lead_time_days']);self::assertSame('unknown',$resolver->resolve($poor,$options)->resolution['source']);
    }
    /**
     * Aísla destinos, conserva históricos y revierte una escritura fallida íntegramente.
     * @return void
     */
    public function testPersistenceLocationsImmutabilityRollbackAndConnections(): void
    {
        $erp=$this->app['db']->connection('erp');$erp->statement('CREATE TABLE marker (value TEXT)');$erp->table('marker')->insert(['value'=>'intacto']);$erp->enableQueryLog();
        $this->app->make(PackageMigrator::class)->run();$relation=ProductSupplier::create(['product_id'=>'P','supplier_id'=>'S','manual_lead_time_days'=>0]);
        $store=$this->app->make(SupplierLeadTimeStore::class);$a=$store->persist($this->analyze([$this->row()]),$relation->id);
        $b=$store->persist($this->analyze([$this->row(location:'B')],location:'B'),$relation->id);
        self::assertSame('L',$a->location_id);self::assertSame('B',$b->location_id);self::assertSame(2,$relation->metrics()->count());self::assertSame('observed_first_receipt',$a->estimation_source);
        self::assertSame('exploratory',$a->lead_time_evidence['summary']['observed_first_receipt']['quality']);self::assertSame(0,$relation->fresh()->manual_lead_time_days);self::assertSame('own',$a->getConnectionName());
        try{$a->average_lead_time_days='999';$a->save();self::fail('Debe ser inmutable.');}catch(\LogicException $error){self::assertSame('2.000000',$a->fresh()->average_lead_time_days);}
        SupplierMetric::created(function():void{throw new \RuntimeException('fallo sintético posterior al INSERT');});
        try{$store->persist($this->analyze([$this->row()]),$relation->id);self::fail('Debe fallar.');}catch(\RuntimeException $error){self::assertSame(2,SupplierMetric::count());}
        self::assertSame([],$erp->getQueryLog());self::assertSame('intacto',$erp->table('marker')->value('value'));
        self::assertFalse($erp->getSchemaBuilder()->hasTable('replenishment_supplier_metrics'));
        self::assertSame(11,$this->app['db']->connection('own')->table('replenishment_migrations')->count());
    }
    /**
     * Una primera recepción certificada puede elegirse expresamente; no equivale a la entrega completa.
     * @return void
     */
    public function testExplicitFirstReceiptMeasureAndScopeFailures(): void
    {
        $rows=[$this->row('1',[['2026-01-03','2']]),$this->row('2',[['2026-01-03','2']]),$this->row('3',[['2026-01-03','2']])];
        $history=$this->analyze($rows,true);$resolver=new SupplierLeadTimeResolver();
        self::assertSame('unknown',$resolver->resolve($history,new SupplierLeadTimeOptions())->resolution['source']);
        $r=$resolver->resolve($history,new SupplierLeadTimeOptions(historicalMeasure:'first_receipt'));
        self::assertSame('historical',$r->resolution['source']);self::assertSame('first_receipt',$r->resolution['measure']);self::assertSame('2.000000',$r->resolution['lead_time_days']);
        $wrong=$this->analyze([$this->row(location:'B')]);self::assertSame('scope_mismatch',$wrong->summary['failure_cause']);
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($history,new SupplierLeadTimeOptions(),new ProductSupplier(['product_id'=>'OTHER','supplier_id'=>'S']));
    }
    /**
     * Fechas estimadas incompatibles y cifras sin evidencia no habilitan publicación.
     * @return void
     */
    public function testInvalidEstimatesAndEvidenceCannotBePersisted(): void
    {
        $history=$this->analyze([$this->row(expected:'2025-12-31')]);
        self::assertNull($history->observations[0]->evidence['expected_to_first_days']);self::assertContains('expected_date_incompatible',$history->observations[0]->evidence['limitations']);
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(SupplierLeadTimeStore::class)->persist($this->analyze([$this->row('1',[])]),null);
    }
    /**
     * La cobertura no puede anticipar información futura ni aceptar parámetros estadísticos imposibles.
     * @return void
     */
    public function testInvalidOptionsAndFutureCoverage(): void
    {
        foreach([['minimumOrders'=>1],['minimumCoverage'=>'1.1'],['maximumAgeDays'=>-1],['historicalMeasure'=>'invented']] as $args){
            try{new SupplierLeadTimeOptions(...$args);self::fail('Debe rechazar el criterio.');}catch(\InvalidArgumentException $error){self::assertNotEmpty($error->getMessage());}}
        $proof=new LeadTimeCoverageData('D','1','P','S','L',$this->date('2026-01-01'),$this->date('2026-03-01'),'futura');
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(SupplierLeadTimeAnalyzer::class)->analyze(new LeadTimeFixtureSource([$this->row()]),'P','S','L',$this->date('2026-01-01'),$this->date('2026-02-01'),'America/Caracas',new SupplierLeadTimeOptions(),[$proof],$this->date('2026-02-01'));
    }
    /**
     * Amplía una instalación antigua sin asignar destinos ficticios y revierte sólo el lote nuevo.
     * @return void
     */
    public function testMigrationEvolutionAndRollback(): void
    {
        $connection=$this->app->make(PersistenceConnection::class);$migration=new AddSupplierLeadTimeEvidence();
        $this->app->make(PackageMigrator::class)->run();$connection->migrating(fn()=>$migration->down());
        $own=$connection->connection();$own->table('replenishment_migrations')->where('migration','2026_11_09_000003_add_supplier_lead_time_evidence')->delete();
        $relation=ProductSupplier::create(['product_id'=>'P','supplier_id'=>'S']);
        $old=SupplierMetric::create(['product_supplier_id'=>$relation->id,'analysis_start'=>$this->date('2026-01-01'),'analysis_end'=>$this->date('2026-02-01'),'calculated_at'=>$this->date('2026-02-01'),'algorithm_version'=>'legacy']);
        $this->app->make(PackageMigrator::class)->run();self::assertNull($old->fresh()->location_id);self::assertNull($old->fresh()->lead_time_evidence);
        $this->app->make(PackageMigrator::class)->run(true);self::assertFalse($own->getSchemaBuilder()->hasColumn('replenishment_supplier_metrics','location_id'));self::assertSame(1,SupplierMetric::count());
        self::assertTrue($own->getSchemaBuilder()->hasTable('replenishment_daily_sales'));self::assertSame(10,$own->table('replenishment_migrations')->count());
    }
}

/** Fuente sintética acotada que permite simular un fallo después de emitir evidencia válida. */
final class LeadTimeFixtureSource implements PurchaseHistorySource
{
    /**
     * Recibe filas y fallo opcional.
     * @param array $rows Conciliaciones.
     * @param bool $fail Fallo tardío.
     */
    public function __construct(private array $rows,private bool $fail=false) {}
    /**
     * Consulta identidad sintética.
     * @param string $document Código.
     * @param string $number Número.
     * @param string $locationId Destino.
     * @return PurchaseOrderData|null Orden.
     */
    public function find(string $document,string $number,string $locationId): ?PurchaseOrderData {return $this->rows[0]->order??null;}
    /**
     * Devuelve fila preparada.
     * @param string $document Código.
     * @param string $number Número.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $until Corte.
     * @return PurchaseReconciliationData Fila.
     */
    public function reconcile(string $document,string $number,string $productId,string $locationId,DateTimeImmutable $until): PurchaseReconciliationData {return $this->rows[0];}
    /**
     * Emite evidencia y fallo controlado.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Corte.
     * @return iterable Filas.
     * @throws \RuntimeException Si se solicita el fallo tardío.
     */
    public function between(string $productId,string $locationId,DateTimeImmutable $from,DateTimeImmutable $until): iterable {yield from $this->rows;if($this->fail){throw new \RuntimeException('fallo sintético');}}
    /**
     * No suministra movimientos independientes.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Corte.
     * @return iterable Vacío.
     */
    public function receiptsBetween(string $productId,string $locationId,DateTimeImmutable $from,DateTimeImmutable $until): iterable {return [];}
}
