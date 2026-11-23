<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Services\{ObservedDemandCalculator,ObservedDemandStore,ObservedDemandQuery,SalesHistoryBreakdownService,PolicyDemandResolver,ReplenishmentCalculator,DemandTrendAnalyzer,ExtendedCoverageEvaluator};
use Asisnet\Replenishment\DTO\{ObservedDemandData,ProductCostData};
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Orchestra\Testbench\TestCase;

/** Prueba calendario, cantidades exactas y límites de inferencia sin fuentes reales. */
final class PolicyPlanning732Test extends TestCase
{
    /** Registra dependencias del paquete. @param mixed $app Aplicación temporal. @return array Proveedores. */
    protected function getPackageProviders($app): array { return [ReplenishmentServiceProvider::class]; }
    /** Crea fecha civil. @param string $date Fecha. @return DateTimeImmutable Medianoche operativa. */
    private function date(string $date): DateTimeImmutable { return new DateTimeImmutable($date, new DateTimeZone('America/Caracas')); }
    /** Genera días sintéticos conciliados. @param string $from Inicio. @param string $until Fin. @param callable|null $value Cantidad bruta por fecha. @return ObservedDemandData Serie completa. */
    private function history(string $from, string $until, ?callable $value = null): ObservedDemandData
    {
        $days=[];for($date=$this->date($from);$date<$this->date($until);$date=$date->modify('+1 day')){$gross=$value===null?'3':$value($date);$days[$date->format('Y-m-d')]=['gross_sales_units'=>$gross,'returned_units'=>'1','reversed_return_units'=>'0.5','net_sales_units'=>(string)BigDecimal::of($gross)->minus('0.5'),'event_count'=>3,'base_unit'=>'UND','observation_status'=>'complete','quality_issues'=>[],'inventory_quality_issues'=>[]];}
        return $this->app->make(ObservedDemandCalculator::class)->fromDays('P','L',$this->date($from),$this->date($until),'America/Caracas',$days,'cache');
    }
    /** Comprueba febrero bisiesto, límites lunes-domingo y conciliación exacta de los cuatro componentes. @return void */
    public function testLeapMonthWeeklyReconciliation(): void
    {
        $data=$this->history('2024-02-01','2024-03-01');$result=$this->app->make(SalesHistoryBreakdownService::class)->breakdown($data,true,$data->until);
        self::assertCount(5,$result['rows']);self::assertSame(29,array_sum(array_column($result['rows'],'calendar_days')));self::assertTrue($result['rows'][0]['clipped_week']);self::assertTrue($result['rows'][4]['clipped_week']);
        foreach(ObservedDemandCalculator::QUANTITIES as $field){$total=BigDecimal::zero();foreach($result['rows'] as $week){$total=$total->plus($week[$field]);}self::assertTrue($total->isEqualTo($result['summary'][$field]));}
        $cursor=$data->from;foreach($result['rows'] as $row){self::assertSame($cursor->format(DATE_ATOM),$row['from']);$cursor=new DateTimeImmutable($row['until']);}self::assertEquals($data->until,$cursor);
    }
    /** Distingue mes parcial y huecos, sin acumular totales parciales definitivos. @return void */
    public function testUnknownDayAndPartialYear(): void
    {
        $data=$this->history('2024-01-01','2024-02-10');$days=$data->days;unset($days['2024-02-02']);$data=$this->app->make(ObservedDemandCalculator::class)->fromDays('P','L',$data->from,$data->until,$data->timezone,$days,'cache');
        $result=$this->app->make(SalesHistoryBreakdownService::class)->breakdown($data,false,$this->date('2025-01-01'));
        self::assertNull($result['summary']['gross_sales_units']);self::assertSame(1,$result['summary']['unknown_days']);self::assertTrue($result['summary']['partial_calendar_period']);self::assertNotNull($result['rows'][0]['gross_sales_units']);self::assertNull($result['rows'][1]['gross_sales_units']);self::assertTrue($result['rows'][1]['partial_calendar_period']);
    }
    /** La heurística usa FAC, no netos, y requiere autorización y ventana suficiente. @return void */
    public function testPolicyDailyHeuristicAndManualFallback(): void
    {
        $data=$this->history('2024-01-01','2024-02-01');$policy=['active'=>true,'analysis_days'=>31,'minimum_history_days'=>30,'forecast_horizon_days'=>180];$resolver=$this->app->make(PolicyDemandResolver::class);
        $unknown=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-02-11'));self::assertNull($unknown['quantity']);
        $value=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-02-11'),null,true);self::assertSame('historical_daily_rate_heuristic',$value['method']);self::assertSame('exploratory',$value['quality']);self::assertTrue(BigDecimal::of($value['quantity'])->isEqualTo(30));
        $policy['minimum_history_days']=32;$value=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-02-11'),null,true,'1.25');self::assertSame('manual_daily_rate',$value['method']);self::assertSame('12.50',$value['quantity']);
        $value=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-02-11'),null,true);self::assertNull($value['quantity']);
    }
    /** Prioriza pronóstico compatible sin interpolarlo para tramos parciales. @return void */
    public function testForecastPrecedenceAndIncompatibleDays(): void
    {
        $data=$this->history('2024-01-01','2024-02-01');$policy=['active'=>true,'analysis_days'=>31,'minimum_history_days'=>30,'forecast_horizon_days'=>180];$forecast=['product_id'=>'P','location_id'=>'L','base_unit'=>'UND','signal'=>'verified_gross_sales','cutoff'=>'2024-02-01T00:00:00-04:00','source_reading_status'=>'complete','forecasts'=>[['period_start'=>'2024-02-01','period_end'=>'2024-03-01','quality'=>'usable','forecast_quantity'=>'100']]];
        $resolver=$this->app->make(PolicyDemandResolver::class);$value=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-03-01'),$forecast,true);self::assertSame('usable_monthly_forecast',$value['method']);self::assertSame('100',$value['quantity']);
        $value=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-02-10'),$forecast);self::assertNull($value['quantity']);
        $forecast['product_id']='OTHER';$value=$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-03-01'),$forecast);self::assertNull($value['quantity']);
    }
    /** Comprueba insuficiencias de señal, huecos, pronóstico FAC incompatible y pesos inválidos.
     * @return void */
    public function testPolicySignalsAndUnusableHistoricalRates(): void
    {
        $resolver=$this->app->make(PolicyDemandResolver::class);
        $data=$this->history('2024-01-01','2024-02-01',fn ()=>'0.1');
        $policy=['active'=>true,'analysis_days'=>31,'minimum_history_days'=>30,'forecast_horizon_days'=>180,
            'demand_settings'=>['signal'=>'verified_net_sales']];
        $forecast=['product_id'=>'P','location_id'=>'L','base_unit'=>'UND','signal'=>'verified_gross_sales',
            'cutoff'=>$data->until->format(DATE_ATOM),'source_reading_status'=>'complete','forecasts'=>[
                ['period_start'=>'2024-02-01','period_end'=>'2024-03-01','quality'=>'usable','forecast_quantity'=>'100']]];
        self::assertNull($resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-03-01'),$forecast,true)['quantity']);
        self::assertTrue(BigDecimal::of($data->summary['net_sales_units'])->isNegative());
        self::assertSame('manual_daily_rate',$resolver->resolve($data,$policy,'UND',$data->until,$this->date('2024-03-01'),$forecast,true,'2')['method']);
        $positive=$this->history('2024-01-01','2024-02-01');
        self::assertSame('historical_average',$resolver->resolve($positive,$policy,'UND',$positive->until,$this->date('2024-03-01'),$forecast,true)['method']);
        $forecast['forecasts'][]=['period_start'=>'2024-03-01','period_end'=>'2024-04-01','quality'=>'exploratory','forecast_quantity'=>'9'];
        unset($policy['demand_settings']);
        $parts=$resolver->protection($positive,$policy,'UND',$positive->until,$this->date('2024-03-01'),$this->date('2024-04-01'),$forecast,true,null);
        self::assertSame('historical_average',$parts['before']['method']);
        self::assertSame($parts['before']['method'],$parts['after']['method']);
        $empty=$this->history('2024-01-01','2024-02-01',fn ()=>'0');
        self::assertNull($resolver->resolve($empty,$policy,'UND',$empty->until,$this->date('2024-03-01'),null,true)['quantity']);
        foreach ([[['days'=>30,'weight'=>'90']], [['days'=>30,'weight'=>'0'],['days'=>30,'weight'=>'100']],
            [['days'=>0,'weight'=>'100']], [['days'=>1,'weight'=>'100','offset'=>0]]] as $blocks) {
            try {
                \Asisnet\Replenishment\Services\PolicyDemandSettings::normalize(['strategy'=>'weighted_average','blocks'=>$blocks]);
                self::fail('La política aceptó ponderaciones inválidas.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('',$error->getMessage());
            }
        }
    }

    /** Un solo mes bajo no basta; varios pares descriptivos concordantes sí producen revisión por descenso. @return void */
    public function testTrendNeedsSeveralScales(): void
    {
        $data=$this->history('2024-01-01','2024-12-26',fn($date)=>$date>=$this->date('2024-11-26')?'1':'10');$trend=$this->app->make(DemandTrendAnalyzer::class)->analyze($data);self::assertNotSame('declining',$trend['classification']);
        $data=$this->history('2024-01-01','2024-12-26',fn($date)=>$date>=$this->date('2024-09-27')?'1':'10');$trend=$this->app->make(DemandTrendAnalyzer::class)->analyze($data);self::assertSame('declining',$trend['classification']);self::assertNull($trend['confidence']);
        foreach($trend['windows'] as $window){self::assertSame($window['recent']['from'],$window['previous']['until']);}
    }
    /** Mantiene desconocidos y cero explícito, sin retirar candidatos. @return void */
    public function testCoverageAndSharedBalance(): void
    {
        $evaluator=new ExtendedCoverageEvaluator();$report=$evaluator->evaluateAccumulated('900','540',180,'0','0');self::assertTrue($report['candidate']);self::assertFalse($report['excluded']);self::assertNull($report['conservative_demand']);self::assertFalse($evaluator->evaluate('900',null,180)['candidate']);
        $calculator=new ReplenishmentCalculator();self::assertSame(['projected'=>'-5','target'=>'30','required'=>'35'],$calculator->balance('5','10','0','30','0'));self::assertNull($calculator->balance('5','10','0','30',null)['required']);self::assertSame('0',$calculator->packages('0',['base_units_per_package'=>'12','minimum_packages'=>'10','package_multiple'=>'2'])['packages']);
    }
    /** La semántica de costo desconocida no produce totales; la verificada usa decimales exactos. @return void */
    public function testCostSemanticsAndExactArithmetic(): void
    {
        $unknown=new ProductCostData('P',null,null,null,'fixture',$this->date('2024-01-01'));self::assertNull($unknown->totals('UND','12','2')['subtotal']);
        $cost=new ProductCostData('P','0.123456789012','USD','UND','fixture',$this->date('2024-01-01'),'verified');self::assertSame('2.962962936288',$cost->totals('UND','12','2')['subtotal']);self::assertNull($cost->totals('KG','12','2')['subtotal']);
    }
    /** Compara tasas sólo con períodos completos, evitando porcentajes con denominador cero. @return void */
    public function testComparableVariation(): void
    {
        $service=$this->app->make(SalesHistoryBreakdownService::class);$a=['observation_status'=>'complete','partial_calendar_period'=>false,'base_unit'=>'UND','calendar_days'=>31,'gross_sales_units'=>'93'];$b=array_replace($a,['calendar_days'=>30,'gross_sales_units'=>'60']);self::assertSame('50.000000',$service->variation($a,$b));self::assertNull($service->variation(array_replace($a,['partial_calendar_period'=>true]),$b));self::assertNull($service->variation($a,array_replace($b,['gross_sales_units'=>'0'])));
    }
}
