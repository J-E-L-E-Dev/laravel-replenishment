<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\ForecastOptions;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Asisnet\Replenishment\Forecasting\StatisticalForecastModel;
use Asisnet\Replenishment\Services\ObservedDemandCalculator;
use Asisnet\Replenishment\Services\DemandForecastEngine;
use Asisnet\Replenishment\Services\ForecastTraining;
use Asisnet\Replenishment\Services\ForecastEvaluator;
use Asisnet\Replenishment\Services\ForecastBacktester;
use Asisnet\Replenishment\Services\ForecastStore;
use Asisnet\Replenishment\Services\PackageMigrator;
use Asisnet\Replenishment\Models\DemandForecast;
use Asisnet\Replenishment\Models\ProductMetric;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Orchestra\Testbench\TestCase;

/** Valida modelos, cortes, reservas temporales y persistencia sin servicios o datos comerciales reales. */
final class ForecastTest extends TestCase
{
    /**
     * Registra el proveedor real.
     * @param mixed $app Aplicación aislada.
     * @return array Proveedores.
     */
    protected function getPackageProviders($app): array { return [ReplenishmentServiceProvider::class]; }
    /**
     * Declara dos conexiones independientes y zona operativa.
     * @param mixed $app Aplicación.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        foreach (['own','erp'] as $name) { $app['config']->set('database.connections.'.$name, ['driver'=>'sqlite','database'=>':memory:','prefix'=>'']); }
        $app['config']->set('database.default','erp'); $app['config']->set('replenishment.connection','own'); $app['config']->set('app.timezone','America/Caracas');
    }
    /**
     * Construye una serie ficticia con tasa diaria exacta y posibles días desconocidos.
     * @param callable $rate Tasa por fecha.
     * @param string $from Inicio mensual.
     * @param string $until Corte mensual.
     * @param array $overrides Días modificados.
     * @param string $product Producto.
     * @param string $location Ubicación.
     * @param string $origin Origen declarado.
     * @return ObservedDemandData Serie comercial.
     */
    private function series(callable $rate, string $from='2023-01-01', string $until='2025-01-01', array $overrides=[], string $product='P', string $location='L', string $origin='source'): ObservedDemandData
    {
        $from=new DateTimeImmutable($from.'T00:00:00-04:00'); $until=new DateTimeImmutable($until.'T00:00:00-04:00'); $days=[];
        for($date=$from;$date<$until;$date=$date->modify('+1 day')) {
            $value=$rate($date);$key=$date->format('Y-m-d');
            $days[$key]=array_replace(['gross_sales_units'=>$value,'returned_units'=>'0','reversed_return_units'=>'0','net_sales_units'=>$value,
                'event_count'=>BigDecimal::of($value)->isPositive()?1:0,'base_unit'=>'U','observation_status'=>'complete','quality_issues'=>[],'inventory_quality_issues'=>[]],$overrides[$key]??[]);
        }
        return (new ObservedDemandCalculator())->fromDays($product,$location,$from,$until,'America/Caracas',$days,$origin);
    }
    /**
     * Ejecuta el motor puro con opciones iniciales.
     * @param ObservedDemandData $data Serie.
     * @param ForecastOptions|null $options Opciones.
     * @return \Asisnet\Replenishment\DTO\ForecastData Resultado.
     */
    private function forecast(ObservedDemandData $data, ?ForecastOptions $options=null) { return $this->app->make(DemandForecastEngine::class)->generate($data,$options??new ForecastOptions(horizon:6)); }
    /**
     * Una tasa constante conserva calendario, precisión y preferencia por el método simple.
     * @return void
     */
    public function testConstantDemandAllHorizonsAndLeapYear(): void
    {
        $result=$this->forecast($this->series(fn($d):string=>'1.123456','2022-01-01','2024-01-01'));
        self::assertCount(6,$result->report['forecasts']); self::assertSame('mean',$result->report['forecasts'][0]['method']);
        self::assertSame('U',$result->report['base_unit']);
        self::assertSame('usable',$result->report['forecasts'][0]['quality']);
        self::assertSame('32.580224000000',$result->report['forecasts'][1]['forecast_quantity']);
        foreach([1,2,3,6] as $h){self::assertArrayHasKey($h,$result->report['backtesting']);}
        foreach($result->report['forecasts'] as $row){self::assertNull($row['lower_bound']);self::assertNull($row['upper_bound']);self::assertNull($row['confidence']);self::assertSame('0.000000000000',$row['validation']['mae']);}
    }
    /**
     * Tendencias crecientes y decrecientes no producen extrapolaciones negativas ni infinitas.
     * @return void
     */
    public function testTrendGrowthDeclineAndBounds(): void
    {
        foreach([1,-1] as $direction){
            $data=$this->series(fn($d):string=>(string)($direction===1?((int)$d->format('Y')-2023)*12+(int)$d->format('n'):40-((int)$d->format('Y')-2023)*12-(int)$d->format('n')));
            $result=$this->forecast($data);
            self::assertSame('trend',$result->report['forecasts'][0]['method']);
            self::assertNull($result->report['forecasts'][0]['seasonal_factor']);
            foreach($result->report['forecasts'] as $row){self::assertTrue(BigDecimal::of($row['forecast_quantity'])->isGreaterThanOrEqualTo(0));}
            $a=BigDecimal::of($result->report['forecasts'][0]['model_details']['slope_per_month']);self::assertSame($direction===1,$a->isPositive());
        }
        $fall=$this->series(fn($d):string=>(string)(12-(int)$d->format('n')),'2024-07-01','2025-01-01');
        $rows=$this->app->make(ForecastTraining::class)->periods($fall);
        $clipped=(new StatisticalForecastModel('trend'))->predict($rows,$fall->until,6,new ForecastOptions(window:6));
        self::assertSame('0.000000000000',$clipped['quantity']);self::assertTrue($clipped['details']['clipped']);
    }
    /**
     * TSB requiere intermitencia y suficientes eventos, no se aplica a una serie constante.
     * @return void
     */
    public function testIntermittentEligibilityAndNoSales(): void
    {
        $training=$this->app->make(ForecastTraining::class);$options=new ForecastOptions(horizon:6);
        $data=$this->series(fn($d):string=>in_array((int)$d->format('n'),[2,6,10],true)?'2':'0');$rows=$training->periods($data);
        $tsb=new StatisticalForecastModel('tsb');$prediction=$tsb->predict($rows,$data->until,3,$options);
        self::assertNotNull($prediction);self::assertTrue(BigDecimal::of($prediction['quantity'])->isPositive());
        $constant=$this->series(fn($d):string=>'1');self::assertNull($tsb->predict($training->periods($constant),$constant->until,1,$options));
        $empty=$this->forecast($this->series(fn($d):string=>'0'));
        self::assertSame('0.000000000000',$empty->report['forecasts'][0]['forecast_quantity']);self::assertSame('exploratory',$empty->report['forecasts'][0]['quality']);
        self::assertNull($empty->report['forecasts'][0]['validation']['wape']);
    }
    /**
     * Los huecos cortan entrenamiento y los historiales demasiado cortos no generan ceros.
     * @return void
     */
    public function testInsufficientUnknownAndPartialMonths(): void
    {
        $short=$this->forecast($this->series(fn($d):string=>'1','2024-11-01','2025-01-01'));
        self::assertNull($short->report['forecasts'][0]['forecast_quantity']);self::assertSame('insufficient',$short->report['forecasts'][0]['quality']);
        $unknown=$this->forecast($this->series(fn($d):string=>'1',overrides:['2024-12-05'=>['observation_status'=>'incomplete','quality_issues'=>['source_failure']]]));
        self::assertNull($unknown->report['forecasts'][0]['forecast_quantity']);
        self::assertGreaterThan(0,$unknown->report['backtesting'][1]['candidates']['mean']['validation']['metrics']['excluded_periods']);
        $this->expectException(\InvalidArgumentException::class);$this->forecast($this->series(fn($d):string=>'1','2024-01-15','2025-01-01'));
    }
    /**
     * Las métricas no inventan denominadores, y el signo positivo significa sobreestimación.
     * @return void
     */
    public function testErrorMetricsAndZeroDenominators(): void
    {
        $evaluator=new ForecastEvaluator();$metrics=$evaluator->evaluate([['predicted'=>'5','actual'=>'3','mase_scale'=>'2'],['predicted'=>'1','actual'=>'2','mase_scale'=>'2']])->metrics;
        self::assertSame('3',$metrics['absolute_error']);self::assertSame('1',$metrics['signed_error']);self::assertSame('1.500000000000',$metrics['mae']);
        self::assertSame('0.600000000000',$metrics['wape']);self::assertSame('0.200000000000',$metrics['bias_ratio']);self::assertSame('0.750000000000',$metrics['mase']);
        $zero=$evaluator->evaluate([['predicted'=>'2','actual'=>'0','mase_scale'=>null]])->metrics;self::assertNull($zero['wape']);self::assertNull($zero['mase']);
        self::assertNull($evaluator->evaluate([])->metrics['mae']);
    }
    /**
     * Cambiar la reserva final no modifica la selección ni predicciones de orígenes anteriores.
     * @return void
     */
    public function testNoTemporalLeakageAndSelectionHoldoutSeparation(): void
    {
        $a=$this->forecast($this->series(fn($d):string=>'1'));
        $b=$this->forecast($this->series(fn($d):string=>$d->format('Y-m')>='2024-10'?'100':'1'));
        foreach([1,2,3,6] as $h){
            $first=$a->report['backtesting'][$h]['validation_first_origin'];
            foreach($a->report['backtesting'][$h]['candidates']['mean']['selection']['observations'] as $row){self::assertLessThan($first,$row['target']);self::assertLessThanOrEqual($row['origin'],$row['training_until']);}
        }
        foreach($a->report['backtesting'][1]['candidates'] as $name=>$candidate){self::assertSame($candidate['selection'],$b->report['backtesting'][1]['candidates'][$name]['selection']);}
        self::assertSame($a->report['forecasts'][0]['method'],$b->report['forecasts'][0]['method']);
        $old=$a->report['backtesting'][1]['candidates']['mean']['validation']['observations'][0];$new=$b->report['backtesting'][1]['candidates']['mean']['validation']['observations'][0];
        self::assertSame($old['predicted'],$new['predicted']);self::assertNotSame($old['actual'],$new['actual']);
    }
    /**
     * La estacionalidad sólo usa entrenamiento completo y estable, nunca índices futuros o picos aislados.
     * @return void
     */
    public function testSeasonalityStableUnstableAndTrainingCutoff(): void
    {
        $training=$this->app->make(ForecastTraining::class);$options=new ForecastOptions();$seasonalOptions=new SeasonalityOptions();$model=new StatisticalForecastModel('seasonal');
        $data=$this->series(fn($d):string=>$d->format('m')==='07'?'3':'1','2021-01-01','2025-01-01');$rows=$training->periods($data);
        $stable=$training->seasonal($data,$rows,$seasonalOptions);self::assertSame('recurrent',$stable['quality']);
        self::assertNotNull($model->predict($rows,$data->until,1,$options,$stable));
        $prefix=array_slice($rows,0,24);self::assertNull($training->seasonal($data,$prefix,$seasonalOptions));
        $altered=$this->series(fn($d):string=>$d->format('Y')==='2024'?'999':($d->format('m')==='07'?'3':'1'),'2021-01-01','2025-01-01');
        self::assertSame($training->seasonal($data,array_slice($rows,0,36),$seasonalOptions)['months'],
            $training->seasonal($altered,array_slice($training->periods($altered),0,36),$seasonalOptions)['months']);
        $changed=$stable;$changed['months'][7]['quality']='unstable';self::assertNull($model->predict($rows,$data->until,1,$options,$changed));
        $spike=$this->series(fn($d):string=>$d->format('Y-m')==='2024-07'?'30':'1','2021-01-01','2025-01-01');
        self::assertNull($model->predict($training->periods($spike),$spike->until,1,$options,$training->seasonal($spike,$training->periods($spike),$seasonalOptions)));
    }
    /**
     * La caché antigua conserva estimaciones exploratorias y las devoluciones no cambian la señal bruta.
     * @return void
     */
    public function testStaleCacheAndGrossSignal(): void
    {
        $source=$this->series(fn($d):string=>'1');$a=$this->forecast($source,new ForecastOptions(backtest:false));
        $other=$this->series(fn($d):string=>'1',overrides:['2024-12-01'=>['returned_units'=>'100','net_sales_units'=>'-99']]);$b=$this->forecast($other,new ForecastOptions(backtest:false));
        self::assertSame($a->report['forecasts'][0]['forecast_quantity'],$b->report['forecasts'][0]['forecast_quantity']);
        $cache=$this->series(fn($d):string=>'1',overrides:['2024-01-01'=>['cached_calculated_at'=>'2000-01-01T00:00:00+00:00']],origin:'cache');
        $stale=$this->forecast($cache);self::assertTrue($stale->report['cache_stale']);self::assertSame('exploratory',$stale->report['forecasts'][0]['quality']);
    }
    /**
     * La consulta exclusiva de caché nunca resuelve adaptadores ni accede a ERP.
     * @return void
     */
    public function testCacheOnlyQueryAndUnknownPublicationRejection(): void
    {
        $this->app->make(PackageMigrator::class)->run();
        $data=$this->series(fn($d):string=>'1','2024-01-01','2025-01-01');
        $this->app->make(\Asisnet\Replenishment\Services\ObservedDemandStore::class)->persist($data);
        $erp=$this->app['db']->connection('erp');$erp->enableQueryLog();
        $result=$this->app->make(\Asisnet\Replenishment\Services\ForecastQuery::class)->analyze('P','L',$data->from,$data->until,2,true,false);
        self::assertSame('cache',$result->report['origin']);self::assertFalse($result->report['cache_stale']);self::assertSame([],$erp->getQueryLog());
        \Asisnet\Replenishment\Models\DailySale::where('sales_date','2024-12-31')->first()->update(['algorithm_version'=>'old-version']);
        $incompatible=$this->app->make(\Asisnet\Replenishment\Services\ForecastQuery::class)->analyze('P','L',$data->from,$data->until,1,true,false);
        self::assertNull($incompatible->report['forecasts'][0]['forecast_quantity']);self::assertSame([],$erp->getQueryLog());
        $short=$this->forecast($this->series(fn($d):string=>'1','2024-12-01','2025-01-01'),new ForecastOptions(backtest:false));
        $this->expectException(\InvalidArgumentException::class);$this->app->make(ForecastStore::class)->persist($short);
    }
    /**
     * Los pronósticos y auditoría se añaden juntos, son inmutables y conservan alcances separados.
     * @return void
     */
    public function testPersistenceImmutableAtomicAndConnectionIsolation(): void
    {
        $this->app->make(PackageMigrator::class)->run();$erp=$this->app['db']->connection('erp');$erp->enableQueryLog();$store=$this->app->make(ForecastStore::class);
        $result=$this->forecast($this->series(fn($d):string=>'1'),new ForecastOptions(horizon:3,backtest:false));$first=$store->persist($result);$second=$store->persist($result);
        self::assertSame(6,DemandForecast::count());self::assertNotSame($first['forecast_ids'],$second['forecast_ids']);
        self::assertSame('exploratory',ProductMetric::find($first['audit_metric_id'])->observed_sales_summary['forecast_audit']['forecasts'][0]['quality']);
        self::assertNull(DemandForecast::first()->lower_bound);self::assertNull(DemandForecast::first()->confidence);self::assertSame([],$erp->getQueryLog());
        $other=$this->forecast($this->series(fn($d):string=>'2',product:'OTHER',location:'SECOND'),new ForecastOptions(horizon:1,backtest:false));$store->persist($other);
        self::assertSame(1,DemandForecast::where('product_id','OTHER')->where('location_id','SECOND')->count());
        ProductMetric::creating(function():void{throw new \RuntimeException('Fallo sintético de auditoría.');});
        try{$store->persist($result);self::fail('Debe revertir.');}catch(\RuntimeException $error){self::assertSame(7,DemandForecast::count());}finally{ProductMetric::flushEventListeners();}
        $this->expectException(\LogicException::class);DemandForecast::first()->update(['forecast_quantity'=>'9']);
    }
}
