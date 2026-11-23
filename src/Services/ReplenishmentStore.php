<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ReplenishmentPlanData;
use Asisnet\Replenishment\Models\{ReplenishmentRun,ReplenishmentResult,SupplierPresentation};
use InvalidArgumentException;

/** Publica ejecución y resultado juntos, conservando hipótesis y fotografía comercial. */
final class ReplenishmentStore
{
    /**
     * Recibe persistencia propia y comprobador de vigencia.
     * @param PersistenceConnection $connection Destino propio.
     * @param ReplenishmentQuery $query Lector de revisiones.
     */
    public function __construct(private PersistenceConnection $connection,private ReplenishmentQuery $query) {}
    /**
     * Añade históricos únicamente por petición explícita del consumidor.
     * @param ReplenishmentPlanData $plan Resultado terminado, incluso insuficiente o simulado.
     * @return array Identidades propias de ejecución y resultado.
     * @throws InvalidArgumentException Si la presentación cambió entre cálculo y publicación.
     * @throws \Throwable Si falla cualquier escritura; revierte ambas filas.
     */
    public function persist(ReplenishmentPlanData $plan): array
    {
        return $this->connection->transaction(function()use($plan):array{
            $r=$plan->report;$r['storage_rounding']='Columnas decimales a seis posiciones HALF_UP; parámetros y explicación conservan operaciones exactas.';$snapshot=$r['presentation'];
            if($snapshot!==null){
                $model=SupplierPresentation::query()->lockForUpdate()->findOrFail($snapshot['supplier_presentation_id']);
                $current=$this->query->presentation($model);if($current!==$snapshot){throw new InvalidArgumentException('La presentación o su vigencia cambió; repita el cálculo antes de publicar.');}
            }
            $run=ReplenishmentRun::create(['started_at'=>now(),'finished_at'=>now(),'analysis_start'=>$r['horizon_start'],'analysis_end'=>$r['horizon_end'],
                'algorithm_version'=>$r['algorithm_version'],'status'=>'completed','parameters_json'=>$r]);
            $q=array_map(fn($value)=>$value===null?null:(string)\Brick\Math\BigDecimal::of($value)->toScale(6,\Brick\Math\RoundingMode::HALF_UP),$r['quantities']);
            $result=ReplenishmentResult::create(['replenishment_run_id'=>$run->id,'product_id'=>$r['product_id'],'location_id'=>$r['location_id'],'supplier_id'=>$r['supplier_id'],
                'supplier_presentation_id'=>$snapshot['supplier_presentation_id']??null,'available_stock'=>$q['available_stock'],
                'verified_incoming'=>in_array($r['incoming']['status'],['certified_absent','certified_positive'],true)?$r['incoming']['total']:null,
                'inventory_position'=>$q['inventory_position'],'lead_time_demand'=>$q['consumption_before_receipt'],'safety_stock'=>$q['safety_stock'],'reorder_point'=>$q['reorder_point'],
                'target_stock'=>$q['target_stock'],'required_base_units'=>$q['required_base_units'],'suggested_packages'=>$q['suggested_packages'],'suggested_base_units'=>$q['suggested_base_units'],
                'projected_coverage_days'=>null,'suggested_order_date'=>$r['dates']['indicative_order'],'expected_receipt_date'=>$r['dates']['target_receipt'],'status'=>$r['status'],
                'explanation'=>json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            return ['run_id'=>$run->id,'result_id'=>$result->id];
        });
    }
}
