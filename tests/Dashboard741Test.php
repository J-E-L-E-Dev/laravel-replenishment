<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Asisnet\Replenishment\DTO\DashboardScope;
use Asisnet\Replenishment\Models\{PurchaseProposal,ProposalGeneration,ProposalGenerationItem,DemandForecast,SeasonalPattern,ProductMetric,DailySale};
use Asisnet\Replenishment\Services\{ReplenishmentDashboardQuery,DashboardSalesQuery,DashboardAnalysisQuery,ObservedDemandCalculator,PersistenceConnection};
use Orchestra\Testbench\TestCase;
use DateTimeImmutable;

/** Verifica lecturas aisladas, calendarios y versiones sin bases comerciales ni recálculos. */
final class Dashboard741Test extends TestCase
{
    /** Registra el proveedor real. @param mixed $app Aplicación aislada. @return array Proveedores. */
    protected function getPackageProviders($app): array { return [ReplenishmentServiceProvider::class]; }
    /** Fija SQLite temporal sin conexiones del ERP. @param mixed $app Aplicación. @return void */
    protected function defineEnvironment($app): void { $app['config']->set('database.default','testing');$app['config']->set('database.connections.testing',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$app['config']->set('replenishment.connection','testing'); }
    /** Migra únicamente la base efímera de pruebas. @return void */
    protected function setUp(): void { parent::setUp();$this->artisan('replenishment:migrate',['--force'=>true])->assertExitCode(0); }
    /** Crea una generación sintética con una línea para probar duplicación entre ejecuciones. @param string $supplier Proveedor. @param string $location Destino. @param string $status Estado. @return ProposalGeneration Generación efímera. */
    private function generation(string $supplier='S',string $location='L',string $status='ready'): ProposalGeneration
    {
        $relation=\Asisnet\Replenishment\Models\ProductSupplier::firstOrCreate(['product_id'=>'P','supplier_id'=>$supplier],['active'=>true]);
        $g=ProposalGeneration::create(['request_id'=>(string)\Illuminate\Support\Str::uuid(),'request_hash'=>str_repeat('a',64),'supplier_id'=>$supplier,'location_id'=>$location,'actor_id'=>'1','status'=>$status,'version'=>1,'snapshot'=>['policy'=>['name'=>'Política de prueba']]]);
        ProposalGenerationItem::create(['generation_id'=>$g->id,'relation_id'=>$relation->id,'product_id'=>'P','status'=>$status==='processing'?'pending':'insufficient_data','result'=>$status==='processing'?null:['trend'=>['classification'=>'insufficient']]]);return $g;
    }
    /** Distingue cabeceras, líneas y únicos; filtra proveedor, destino, fecha y estado. @return void */
    public function testOperationalScopeAndUniqueProducts(): void
    {
        $day=date('Y-m-d');$until=date('Y-m-d',strtotime('+1 day'));$scope=new DashboardScope('L',$day,$until,'S');$query=$this->app->make(ReplenishmentDashboardQuery::class);
        self::assertSame(0,$query->indicators($scope)['proposal_total']);
        PurchaseProposal::create(['supplier_id'=>'S','location_id'=>'L','status'=>'draft']);PurchaseProposal::create(['supplier_id'=>'S','location_id'=>'L','status'=>'approved']);PurchaseProposal::create(['supplier_id'=>'OTHER','location_id'=>'L','status'=>'draft']);
        $this->generation();$this->generation();$this->generation('S','L','processing');$this->generation('OTHER');$this->generation('S','OTHER');
        $result=$query->indicators($scope);self::assertSame(2,$result['proposal_total']);self::assertSame(3,$result['generation_total']);self::assertSame(1,$result['unique_products']);self::assertSame(2,$result['analysis_lines']);self::assertSame(1,$result['resumable']);self::assertSame(2,$result['insufficient_lines']);
        self::assertSame(1,$query->indicators(new DashboardScope('L',$day,$until,'S','draft'))['proposal_total']);self::assertSame(0,$query->indicators(new DashboardScope('L','2000-01-01','2000-02-01','S'))['generation_total']);
        $rows=$query->proposals($scope);self::assertNull($rows[0]->policy_name);self::assertNull($rows[0]->last_event_actor);self::assertCount(3,$query->generations($scope));
    }
    /** Contrasta los estados técnicos de D2 con las cabeceras y decisiones por producto único. @return void */
    public function testDurableWorkIndicators(): void
    {
        foreach ([['ready','recoverable_error','reused'],['ready','intervention_required','refreshed'],['processing','preparing',null]] as [$status,$technical,$origin]) {
            $g=$this->generation('S','L',$status);
            $line=ProposalGenerationItem::where('generation_id',$g->id)->firstOrFail();
            $line->technical_state=$technical;
            $line->statistics_origin=$origin;
            $line->status=$status==='ready'?'error':'pending';
            $line->save();
        }
        $scope=new DashboardScope('L',date('Y-m-d'),date('Y-m-d',strtotime('+1 day')),'S');
        $stats=$this->app->make(ReplenishmentDashboardQuery::class)->indicators($scope);
        self::assertSame(['pending'=>0,'active'=>1,'recoverable_error'=>1,'intervention_required'=>1,'reused'=>1,'refreshed'=>1,
            'undecided_lines'=>2,'undecided_products'=>1],$stats['work']);
        self::assertSame(3,$stats['generation_total']);
        self::assertSame(2,$stats['analysis_lines']);
        self::assertSame(1,$stats['unique_products']);
    }

    /** Fabrica un día materializado válido con neto exacto. @param string $date Fecha. @param string $gross Venta bruta. @return void */
    private function day(string $date,string $gross='2'): void
    {
        DailySale::create(['product_id'=>'P','location_id'=>'L','sales_date'=>$date,'observation_timezone'=>'UTC','algorithm_version'=>ObservedDemandCalculator::VERSION,'calculated_at'=>'2024-04-01','base_unit'=>'UND','gross_sales_units'=>$gross,'returned_units'=>$gross==='0'?'0':'1','reversed_return_units'=>$gross==='0'?'0':'0.5','net_sales_units'=>$gross==='0'?'0':'1.5','event_count'=>$gross==='0'?0:3,'observation_status'=>'complete','quality_issues'=>[],'inventory_quality_issues'=>[]]);
    }
    /** Conserva FAC/NCC/NDC, meses desconocidos, parciales y cero; reutiliza semanas conciliadas. @return void */
    public function testSalesCoverageAndCalendar(): void
    {
        for($i=1;$i<=31;$i++){$this->day(sprintf('2024-01-%02d',$i));}for($i=1;$i<=29;$i++){$this->day(sprintf('2024-02-%02d',$i),'0');}
        $query=$this->app->make(DashboardSalesQuery::class);$now=new DateTimeImmutable('2024-03-10',new \DateTimeZone('UTC'));$result=$query->read('P','L','2024-01','2024-04','UTC',$now);
        self::assertSame('62.000000',$result['rows'][0]['gross_sales_units']);self::assertSame('31.000000',$result['rows'][0]['returned_units']);self::assertSame('15.500000',$result['rows'][0]['reversed_return_units']);self::assertSame('46.500000',$result['rows'][0]['net_sales_units']);self::assertSame('0.000000',$result['rows'][1]['gross_sales_units']);self::assertNull($result['rows'][2]['gross_sales_units']);self::assertTrue($result['rows'][2]['partial_calendar_period']);self::assertSame('UND',$result['base_unit']);
        $weeks=$query->weeks('P','L',2024,2,'UTC',$now);self::assertSame(29,array_sum(array_column($weeks['rows'],'calendar_days')));self::assertCount(5,$weeks['rows']);
        self::assertNull($query->read('OTHER','L','2024-01','2024-02','UTC',$now)['rows'][0]['gross_sales_units']);self::assertNull($query->read('P','OTHER','2024-01','2024-02','UTC',$now)['rows'][0]['gross_sales_units']);
        self::assertCount(12,$query->read('P','L','2023-03','2024-03','UTC',$now)['rows']);self::assertCount(24,$query->read('P','L','2022-03','2024-03','UTC',$now)['rows']);
    }
    /** Crea un pronóstico mensual histórico independiente. @param string $generated Fecha de generación. @return DemandForecast Fila inmutable. */
    private function forecast(string $generated): DemandForecast {return DemandForecast::create(['product_id'=>'P','location_id'=>'L','generated_at'=>$generated,'period_start'=>'2025-01-01','period_end'=>'2025-02-01','granularity'=>'monthly','forecast_quantity'=>'10','method'=>'mean','algorithm_version'=>'test']);}
    /** No deduce calidad del método; las versiones y vínculos auditados son independientes. @return void */
    public function testForecastVersionsAndUnknownQuality(): void
    {
        $old=$this->forecast('2024-11-01');$new=$this->forecast('2024-12-01');$query=$this->app->make(DashboardAnalysisQuery::class);
        self::assertNull($query->read('forecast','P','L')['rows'][0]['quality']);self::assertFalse($query->read('forecast','P','L')['complete_set']);
        ProductMetric::create(['product_id'=>'P','location_id'=>'L','calculated_at'=>'2024-12-01','analysis_start'=>'2023-01-01','analysis_end'=>'2024-12-01','algorithm_version'=>'test','observed_sales_summary'=>['demand_forecast_ids'=>[$new->id],'forecast_audit'=>['base_unit'=>'UND','forecasts'=>[['period_start'=>'2025-01-01','period_end'=>'2025-02-01','method'=>'mean','quality'=>'exploratory']]]]]);
        $report=$query->read('forecast','P','L');self::assertSame('exploratory',$report['rows'][0]['quality']);self::assertNull($report['rows'][0]['lower_bound']);self::assertNull($report['rows'][0]['upper_bound']);self::assertSame($new->id,$report['anchor_id']);self::assertNull($query->read('forecast','P','L',$old->id)['rows'][0]['quality']);self::assertCount(2,$query->versions('forecast','P','L'));self::assertSame([],$query->read('forecast','P','OTHER')['rows']);
    }
    /** Los patrones parciales no forman un año ni reciben una calidad inventada. @return void */
    public function testPartialSeasonalityAndReadOnlyQueries(): void
    {
        SeasonalPattern::create(['product_id'=>'P','location_id'=>'L','period_type'=>'monthly','period_number'=>1,'seasonal_index'=>'1.2','mean_demand'=>'10','historical_years'=>2,'period_class'=>'possible','calculated_at'=>'2024-01-01','algorithm_version'=>'test']);
        $connection=$this->app->make(PersistenceConnection::class)->connection();$connection->enableQueryLog();$query=$this->app->make(DashboardAnalysisQuery::class);$result=$query->read('seasonality','P','L');self::assertFalse($result['complete_set']);self::assertSame('possible',$result['rows'][0]['quality']);self::assertNull($result['quality']);
        foreach($connection->getQueryLog() as $entry){self::assertMatchesRegularExpression('/^select/i',$entry['query']);}
    }

    /** Comprueba versiones estacionales completas vinculadas sin recomputar ni mezclar índices. @return void */
    public function testCompleteSeasonalityVersions(): void
    {
        $ids=[];for($month=1;$month<=12;$month++){$ids[]=SeasonalPattern::create(['product_id'=>'P','location_id'=>'L','period_type'=>'monthly','period_number'=>$month,'seasonal_index'=>'1','mean_demand'=>'10','historical_years'=>3,'period_class'=>'none_detected','calculated_at'=>'2024-02-01','algorithm_version'=>'test'])->id;}
        ProductMetric::create(['product_id'=>'P','location_id'=>'L','calculated_at'=>'2024-02-01','analysis_start'=>'2020-01-01','analysis_end'=>'2023-01-01','algorithm_version'=>'test','observed_sales_summary'=>['seasonal_pattern_ids'=>$ids,'seasonality'=>['base_unit'=>'UND','quality'=>'none_detected','accepted_years'=>[2020,2021,2022]]]]);
        SeasonalPattern::create(['product_id'=>'P','location_id'=>'L','period_type'=>'monthly','period_number'=>1,'seasonal_index'=>'2','calculated_at'=>'2024-03-01','algorithm_version'=>'test']);
        $query=$this->app->make(DashboardAnalysisQuery::class);self::assertFalse($query->read('seasonality','P','L')['complete_set']);$old=$query->read('seasonality','P','L',$ids[0]);self::assertTrue($old['complete_set']);self::assertCount(12,$old['rows']);self::assertSame('none_detected',$old['quality']);self::assertSame('1.000000',$old['rows'][0]['seasonal_index']);self::assertCount(2,$query->versions('seasonality','P','L'));
    }
    /** Verifica calidad utilizable exclusivamente vinculada y rechazo de vínculos incompletos. @return void */
    public function testUsableForecastRequiresCompleteLink(): void
    {
        $row=$this->forecast('2024-11-01');ProductMetric::create(['product_id'=>'P','location_id'=>'L','calculated_at'=>'2024-11-01','analysis_start'=>'2020-01-01','analysis_end'=>'2024-11-01','algorithm_version'=>'test','observed_sales_summary'=>['demand_forecast_ids'=>[$row->id],'forecast_audit'=>['base_unit'=>'UND','forecasts'=>[['period_start'=>'2025-01-01','period_end'=>'2025-02-01','method'=>'mean','quality'=>'usable']]]]]);
        $query=$this->app->make(DashboardAnalysisQuery::class);self::assertSame('usable',$query->read('forecast','P','L')['rows'][0]['quality']);
        ProductMetric::create(['product_id'=>'P','location_id'=>'L','calculated_at'=>'2024-11-01','analysis_start'=>'2020-01-01','analysis_end'=>'2024-11-01','algorithm_version'=>'test','observed_sales_summary'=>['demand_forecast_ids'=>[$row->id,999999],'forecast_audit'=>['quality'=>'usable']]]);
        self::assertNull($query->read('forecast','P','L')['rows'][0]['quality']);self::assertFalse($query->read('forecast','P','L')['complete_set']);
    }
    /** Pagina cabeceras reales y conserva subtotales por moneda sólo en la ejecución seleccionada. @return void */
    public function testPaginationDecisionsAndStoredTrend(): void
    {
        $scope=new DashboardScope('L',date('Y-m-d'),date('Y-m-d',strtotime('+1 day')),'S');for($i=0;$i<27;$i++){PurchaseProposal::create(['supplier_id'=>'S','location_id'=>'L','status'=>'draft']);}
        $g=$this->generation();$row=ProposalGenerationItem::where('generation_id',$g->id)->first();$row->decision=['excluded'=>false,'cost'=>['subtotal'=>'1.25','currency'=>'USD']];$row->save();$other=$this->generation();$second=ProposalGenerationItem::where('generation_id',$other->id)->first();$second->decision=['excluded'=>true];$second->save();
        $query=$this->app->make(ReplenishmentDashboardQuery::class);self::assertCount(25,$query->proposals($scope));self::assertCount(2,$query->proposals($scope,2));self::assertSame(27,$query->proposals($scope)->total());$report=$query->decisions($scope,$g->id);self::assertSame(['USD'=>'1.25'],$report['totals_by_currency']);self::assertSame(0,$report['excluded']);self::assertSame(1,$query->decisions($scope,$other->id)['excluded']);self::assertSame('insufficient',$query->trend($scope,'P')['trend']['classification']);
    }
    /** Una instalación incompleta produce diagnóstico y nunca migración automática. @return void */
    public function testMissingMigrationIsExplicit(): void
    {
        $this->artisan('replenishment:migrate',['--rollback'=>true,'--force'=>true])->assertExitCode(0);$this->expectException(\LogicException::class);$this->expectExceptionMessage('000006');$this->app->make(ReplenishmentDashboardQuery::class)->assertInstalled();
    }

    /** Permite meses materializados sin días, pero no inventa unidad, semanas ni totales anuales. @return void */
    public function testMonthlyMetricWithoutDailyCache(): void
    {
        $from=new DateTimeImmutable('2024-01-01',new \DateTimeZone('UTC'));$until=$from->modify('+1 month');$days=[];
        for($day=$from;$day<$until;$day=$day->modify('+1 day')){$days[$day->format('Y-m-d')]=['gross_sales_units'=>'2','returned_units'=>'1','reversed_return_units'=>'0.5','net_sales_units'=>'1.5','event_count'=>3,'base_unit'=>'UND','observation_status'=>'complete','quality_issues'=>[],'inventory_quality_issues'=>[]];}
        $data=$this->app->make(ObservedDemandCalculator::class)->fromDays('P','L',$from,$until,'UTC',$days,'source');
        ProductMetric::create(['product_id'=>'P','location_id'=>'L','calculated_at'=>'2024-02-01','analysis_start'=>$from,'analysis_end'=>$until,'algorithm_version'=>ObservedDemandCalculator::VERSION,'observed_sales_summary'=>$data->audit()]);
        $result=$this->app->make(DashboardSalesQuery::class)->read('P','L','2024-01','2024-02','UTC',new DateTimeImmutable('2024-03-01'));
        self::assertSame('62.000000',$result['rows'][0]['gross_sales_units']);self::assertFalse($result['rows'][0]['weeks_available']);self::assertSame('metric_monthly',$result['rows'][0]['source']);self::assertNull($result['rows'][0]['base_unit']);self::assertNull($result['years'][0]['summary']['gross_sales_units']);
    }
}
