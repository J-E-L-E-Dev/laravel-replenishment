<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\DTO\InventoryParameterData;
use Asisnet\Replenishment\Services\{InventoryParameterCalculator,ReplenishmentCalculator};
use PHPUnit\Framework\TestCase;

/** Verifica proyecciones exactas de la evidencia y límites de una decisión comercial. */
final class InventoryParameterCalculatorTest extends TestCase
{
    /** Genera el ejemplo de siete unidades en 365 días con dos tramos de 90. @return array Plan congelado. */
    private function plan(): array
    {
        $part=['days'=>90,'quantity'=>'1.726027397260','quantity_exact'=>'126/73','rate_exact'=>'7/365','quality'=>'exploratory','method'=>'historical_average'];
        return ['product_id'=>'001','base_unit'=>'U','as_of'=>'2026-01-01','policy'=>['target_coverage_days'=>90],
            'lead_time'=>['lead_time_days'=>'90'],'safety_units'=>'0','demand_before_receipt'=>$part,'demand_after_receipt'=>$part];
    }

    /** Compara el plan con una unidad de precisión explícita. @param array $plan Evidencia.
     * @param int|null $scale Decimales de inventario. @param string $unit Unidad actual. @return array Diagnóstico. */
    private function snapshot(array $plan,?int $scale=2,string $unit='U'): array
    {
        $master=new InventoryParameterData('001','Producto',$unit,$scale,array_fill_keys(InventoryParameterData::FIELDS,null));
        return (new InventoryParameterCalculator(new ReplenishmentCalculator()))->compare($plan,$master);
    }

    /** Comprueba tasas racionales, techo y seguridad una sola vez con inventario entero o fraccionario. @return void */
    public function testExactRateAndSafety(): void
    {
        $plan=$this->plan();
        $data=$this->snapshot($plan);
        self::assertSame(['minimum_days'=>'90','maximum_days'=>'180','reorder_days'=>'90','minimum'=>'1.73','maximum'=>'3.46','reorder_point'=>'2'],$data['suggested']);
        $plan['safety_units']='1.25';
        self::assertSame('4.71',$this->snapshot($plan)['suggested']['maximum']);
        self::assertSame('3',$this->snapshot($plan,0)['suggested']['minimum']);
        self::assertSame('3',$this->snapshot($plan)['suggested']['reorder_point']);
        self::assertNull($this->snapshot($plan,null)['suggested']['minimum']);
        $plan['safety_units']=null;
        self::assertNull($this->snapshot($plan)['suggested']['minimum']);
        self::assertSame('180',$this->snapshot($plan)['suggested']['maximum_days']);
        self::assertFalse($this->snapshot($plan,0,'KG')['compatible']);
        $invalid=$this->plan();
        $invalid['demand_before_receipt']['quality']='insufficient';
        self::assertNull($this->snapshot($invalid)['suggested']['reorder_point']);
        $plan['lead_time']['lead_time_days']=null;
        self::assertNull($this->snapshot($plan)['suggested']['reorder_days']);
    }

    /** Respeta meses completos desde el análisis, sin desplazar la cobertura al mes posterior ni prorratear. @return void */
    public function testDifferentForecastHorizons(): void
    {
        $plan=$this->plan();
        $plan['policy']['target_coverage_days']=31;
        $plan['lead_time']['lead_time_days']='59';
        $months=[];
        foreach ([['2026-01-01','2026-02-01','1'],['2026-02-01','2026-03-01','4'],['2026-03-01','2026-04-01','8']] as [$from,$until,$q]) {
            $months[]=['period_start'=>$from,'period_end'=>$until,'quality'=>'usable','forecast_quantity'=>$q];
        }
        $plan['demand_before_receipt']=['method'=>'usable_monthly_forecast','quality'=>'usable','days'=>59,'quantity'=>'5','months'=>array_slice($months,0,2)];
        $plan['demand_after_receipt']=['method'=>'usable_monthly_forecast','quality'=>'usable','days'=>31,'quantity'=>'8','months'=>[$months[2]]];
        $values=$this->snapshot($plan)['suggested'];
        self::assertSame('1.00',$values['minimum']);
        self::assertSame('5',$values['reorder_point']);
        self::assertSame('13.00',$values['maximum']);
        $plan['policy']['target_coverage_days']=15;
        self::assertNull($this->snapshot($plan)['suggested']['minimum']);
    }

    /** Conserva anomalías de origen y bloquea decisiones incoherentes, errores de unidad y fracciones indivisibles. @return void */
    public function testInheritedAndConflictingValues(): void
    {
        $calc=new InventoryParameterCalculator(new ReplenishmentCalculator());
        $snapshot=$this->snapshot($this->plan(),0);
        $snapshot['current']['minimum']='-1';
        $keep=array_fill_keys(InventoryParameterData::FIELDS,['mode'=>'keep']);
        self::assertSame('not_applicable',$calc->decide($snapshot,$keep)['status']);
        $snapshot['current']['minimum']=null;
        foreach ([
            ['minimum'=>['mode'=>'custom','value'=>'1.5']],
            ['minimum'=>['mode'=>'custom','value'=>'3'],'maximum'=>['mode'=>'custom','value'=>'2']],
            ['minimum_days'=>['mode'=>'custom','value'=>'6'],'maximum_days'=>['mode'=>'custom','value'=>'5']],
        ] as $change) {
            try { $calc->decide($snapshot,$change+$keep); self::fail('Debió rechazar la decisión.'); }
            catch (\InvalidArgumentException $error) { self::assertNotSame('',$error->getMessage()); }
        }
        $missing=$calc->compare($this->plan(),null);
        self::assertFalse($missing['compatible']);
        self::assertSame('not_applicable',$calc->decide($missing,$keep)['status']);
        $this->expectException(\InvalidArgumentException::class);
        $calc->decide($missing,['minimum'=>['mode'=>'custom','value'=>'1']]+$keep);
    }

    /** Rechaza rango, escala, incoherencia e inferencia de demanda desconocida. @return void */
    public function testRangeAndDecisions(): void
    {
        $calc=new InventoryParameterCalculator(new ReplenishmentCalculator());
        foreach ([['minimum','10000000000000000'],['maximum','0.001'],['reorder_point','1000000000'],['reorder_days','10000'],
            ['minimum','-1'],['maximum','1e3']] as [$key,$value]) { self::assertFalse($calc->valid($key,$value,2)); }
        self::assertTrue($calc->valid('minimum','9999999999999999.99',2));
        self::assertTrue($calc->valid('reorder_point','999999999',0));
        $plan=$this->plan();
        $plan['demand_before_receipt']=$plan['demand_after_receipt']=['quantity'=>null,'quality'=>'insufficient'];
        $row=$this->snapshot($plan);
        self::assertNull($row['suggested']['maximum']);
        $choices=array_fill_keys(InventoryParameterData::FIELDS,['mode'=>'keep']);
        $choices['minimum']=['mode'=>'custom','value'=>'2.50'];
        $choices['maximum']=['mode'=>'custom','value'=>'3'];
        self::assertSame('approved',$calc->decide($row,$choices)['status']);
        $choices['reorder_point']=['mode'=>'custom','value'=>'4'];
        $this->expectException(\InvalidArgumentException::class);
        $calc->decide($row,$choices);
    }
}
