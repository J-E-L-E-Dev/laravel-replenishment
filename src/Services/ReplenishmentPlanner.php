<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\{AvailabilityData,ReplenishmentOptions,ReplenishmentPlanData,SafetyStockEvidenceData};
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Combina evidencia normalizada sin duplicar disponibilidad, pronósticos ni reglas de proveedores. */
final class ReplenishmentPlanner
{
    /** @var string Versión de fórmulas y reglas de simulación. */
    public const VERSION='replenishment-plan-1.0';
    /**
     * Recibe aritmética y cobertura temporal.
     * @param ReplenishmentCalculator $calculator Operaciones exactas.
     */
    public function __construct(private ReplenishmentCalculator $calculator) {}
    /**
     * Planifica un producto sin efectos secundarios ni sustituciones tácitas de NULL.
     * @param AvailabilityData $availability Disponibilidad ya calculada por 3.1.
     * @param string $supplierId Proveedor solicitado.
     * @param string $baseUnit Unidad del producto.
     * @param ReplenishmentOptions $options Escenario y horizonte.
     * @param array|null $forecast Informe del motor aprobado, con identidad y unidad.
     * @param array $leadTime Resolución del plazo existente.
     * @param array $incoming Pendientes completos certificados o desconocidos.
     * @param array|null $presentation Instantánea validada y vigente, o desconocida.
     * @param SafetyStockEvidenceData|null $safetyEvidence Calibración externa real; nunca producida por la CLI actual.
     * @return ReplenishmentPlanData Resultado con cantidades parciales y motivos explícitos.
     * @throws InvalidArgumentException Si el escenario o sus fuentes mezclan ámbitos.
     */
    public function plan(AvailabilityData $availability,string $supplierId,string $baseUnit,ReplenishmentOptions $options,?array $forecast,array $leadTime,array $incoming,?array $presentation=null,?SafetyStockEvidenceData $safetyEvidence=null): ReplenishmentPlanData
    {
        if($supplierId===''||$baseUnit===''||($options->scenarioUnit!==null&&$options->scenarioUnit!==$baseUnit)){throw new InvalidArgumentException('La unidad del escenario debe coincidir con la unidad base del producto.');}
        foreach(['product_id'=>$availability->productId,'location_id'=>$availability->locationId] as $key=>$value){if($forecast!==null&&($forecast[$key]??null)!==$value){throw new InvalidArgumentException('El pronóstico pertenece a otro alcance.');}}
        foreach(['product_id'=>$availability->productId,'supplier_id'=>$supplierId,'location_id'=>$availability->locationId] as $key=>$value){if(isset($leadTime[$key])&&$leadTime[$key]!==$value){throw new InvalidArgumentException('El plazo pertenece a otro alcance.');}}
        if($forecast!==null&&(!isset($forecast['cutoff'])||new \DateTimeImmutable($forecast['cutoff'])>$options->asOf)){throw new InvalidArgumentException('El pronóstico debe declarar un corte no posterior a la evaluación.');}
        $issues=[];$assumptions=[];$warnings=$leadTime['warnings']??[];$start=$options->targetReceipt;$end=$start->modify('+'.$options->horizon.' months');
        $monthly=$start->format('d')==='01';if(!$monthly){$issues[]='target_not_month_boundary';}
        $demand=$this->calculator->demand($forecast,$start,$end,$baseUnit);
        if($options->monthlyDemand!==null){$assumptions['manual_monthly_demand']=$options->monthlyDemand;$demand=['quantity'=>$monthly?(string)BigDecimal::of($options->monthlyDemand)->multipliedBy($options->horizon):null,'quality'=>'manual','months'=>[]];}
        if($demand['quality']==='exploratory'){$assumptions['exploratory_forecast']=true;}
        if($demand['quantity']===null){$issues[]='horizon_demand_unknown';}
        $before=$this->calculator->demand($forecast,$options->asOf,$start,$baseUnit);
        if($options->consumptionBeforeReceipt!==null){$assumptions['manual_consumption_before_receipt']=$options->consumptionBeforeReceipt;$before=['quantity'=>$options->consumptionBeforeReceipt,'quality'=>'manual','months'=>[]];}
        if($before['quality']==='exploratory'){$assumptions['exploratory_prior_consumption']=true;}
        if($before['quantity']===null){$issues[]='consumption_before_receipt_unknown';}
        $safety=null;$safetySource='unknown';
        if($safetyEvidence!==null){
            if($safetyEvidence->productId!==$availability->productId||$safetyEvidence->locationId!==$availability->locationId||$safetyEvidence->baseUnit!==$baseUnit||$safetyEvidence->from!=$options->asOf||$safetyEvidence->until!=$end){throw new InvalidArgumentException('La calibración de seguridad debe cubrir exactamente evaluación, recepción y horizonte.');}
            $safety=$safetyEvidence->quantity;$safetySource='externally_calibrated';
        }
        if($options->safetyUnits!==null){$safety=$options->safetyUnits;$safetySource='manual_scenario';$assumptions['manual_safety_units']=$safety;}
        if($safety===null){$issues[]='safety_stock_unknown';}
        $incomingOriginal=$incoming;
        if(($incoming['status']??'unknown')==='unknown'&&$options->assumeNoIncoming){$assumptions['assume_no_incoming']=true;$incoming=array_replace($incoming,['status'=>'assumed_absent','total'=>'0','by_target'=>'0','calendar_known'=>true]);}
        if(($incoming['total']??null)===null){$issues[]='incoming_unknown';}
        elseif(($incoming['by_target']??null)===null){$issues[]='incoming_calendar_unknown';}
        $available=$availability->available;if($available===null){$issues[]='availability_unknown';}
        if($availability->observedAt===null||$availability->observedAt->setTimezone($options->asOf->getTimezone())->format('Y-m-d')!==$options->asOf->format('Y-m-d')){$issues[]='inventory_observation_date_mismatch';$available=null;}
        $position=$available!==null&&($incoming['total']??null)!==null?(string)BigDecimal::of($available)->plus($incoming['total']):null;
        $balance=$this->calculator->balance($available,$before['quantity'],$incoming['by_target']??null,$demand['quantity'],$safety);
        $projected=$balance['projected'];$target=$balance['target'];$required=$balance['required'];
        $lead=$leadTime;$days=$lead['lead_time_days']??null;
        if($options->leadTimeDays!==null){$assumptions['manual_lead_time_days']=$options->leadTimeDays;$days=(string)$options->leadTimeDays;$lead=array_replace($lead,['lead_time_days'=>$days,'source'=>'manual_scenario','quality'=>'explicit_scenario','measure'=>'configured_commercial_lead_time']);}
        $orderDate=null;$calendarDays=null;
        if($days===null){$issues[]='lead_time_unknown';}
        elseif(BigDecimal::of($days)->isNegative()||BigDecimal::of($days)->isGreaterThan(3650)){throw new InvalidArgumentException('El plazo está fuera del intervalo admitido.');}
        elseif(($lead['measure']??null)==='first_receipt'){$issues[]='lead_time_only_first_receipt';}
        elseif(!in_array($lead['measure']??null,['complete_receipt','configured_commercial_lead_time'],true)){$issues[]='lead_time_measure_unknown';}
        elseif(!in_array($lead['source']??null,['manual','historical','policy_default','manual_scenario'],true)||!in_array($lead['quality']??null,['usable','explicit_configuration','explicit_scenario'],true)){$issues[]='lead_time_not_usable';}
        else{$calendarDays=BigDecimal::of($days)->toScale(0,RoundingMode::CEILING)->toInt();$orderDate=$start->modify('-'.$calendarDays.' days')->format('Y-m-d');}
        $late=$orderDate!==null&&$orderDate<$options->asOf->format('Y-m-d');if($late){$warnings[]='La fecha orientativa de pedido ya pasó; el plazo planificado no alcanza la fecha objetivo.';}
        $packages=['packages'=>null,'base_units'=>null,'rounding_excess'=>null];
        if($presentation===null){$issues[]='supplier_presentation_unknown';}
        else{
            if(($presentation['product_id']??null)!==$availability->productId||($presentation['supplier_id']??null)!==$supplierId){throw new InvalidArgumentException('La presentación pertenece a otro producto o proveedor.');}
            if(!($presentation['active']??false)||!($presentation['relation_active']??false)||!($presentation['current_revision']??false)){$issues[]='supplier_presentation_inactive_or_obsolete';}
            elseif($required!==null){$packages=$this->calculator->packages($required,$presentation);}
        }
        $reorder=null;
        if($orderDate!==null&&$safety!==null){
            $orderAt=$start->modify('-'.$calendarDays.' days');
            $leadDemand=$orderAt==$options->asOf?$before:$this->calculator->demand($forecast,$orderAt,$start,$baseUnit);
            if($leadDemand['quality']==='exploratory'){$assumptions['exploratory_reorder_point']=true;}
            if($leadDemand['quantity']!==null){$reorder=(string)BigDecimal::of($leadDemand['quantity'])->plus($safety);}
        }
        $status=$issues!==[]?'insufficient_data':($assumptions!==[]?'simulation':(BigDecimal::of($required)->isZero()?'not_required':'recommended'));
        $warnings[]='La disponibilidad compone lecturas actuales independientes; no garantiza una instantánea transaccional del ERP.';
        $warnings[]='La fecha orientativa es una resta de días calendario, no una fecha óptima de inventario.';
        $warnings[]='No se certifica cobertura intramensual, demanda perdida ni consumo neto definitivo a partir del pronóstico de ventas brutas.';
        return new ReplenishmentPlanData(['algorithm_version'=>self::VERSION,'product_id'=>$availability->productId,'supplier_id'=>$supplierId,'location_id'=>$availability->locationId,'base_unit'=>$baseUnit,
            'status'=>$status,'parameters'=>$options->audit(),'horizon_start'=>$start->format('Y-m-d'),'horizon_end'=>$end->format('Y-m-d'),
            'availability'=>array_replace(get_object_vars($availability),['observedAt'=>$availability->observedAt?->format(DATE_ATOM)]),'incoming'=>$incoming,'incoming_original'=>$incomingOriginal['status']??'unknown',
            'forecast'=>['context'=>array_intersect_key($forecast??[],array_flip(['cutoff','generated_at','timezone','signal','algorithm_version','options','seasonality_options','source_quality_issues','cache_stale','training'])),'source'=>$forecast['origin']??null,'quality'=>$demand['quality'],'months'=>$demand['months'],'prior_months'=>$before['months']],
            'lead_time'=>$lead,'lead_time_original'=>$leadTime,'presentation'=>$presentation,'safety_source'=>$safetySource,'safety_calibration_reference'=>$safetyEvidence?->calibrationReference,
            'quantities'=>['available_stock'=>$available,'inventory_position'=>$position,'horizon_demand'=>$demand['quantity'],'consumption_before_receipt'=>$before['quantity'],'projected_stock_at_receipt'=>$projected,
                'target_stock'=>$target,'safety_stock'=>$safety,'required_base_units'=>$required,'suggested_packages'=>$packages['packages'],'suggested_base_units'=>$packages['base_units'],'rounding_excess'=>$packages['rounding_excess'],
                'minimum_stock'=>$safety,'maximum_stock'=>$target,'reorder_point'=>$reorder,'projected_coverage_days'=>null],
            'dates'=>['target_receipt'=>$start->format('Y-m-d'),'indicative_order'=>$orderDate,'lead_time_calendar_days'=>$calendarDays,'planned_lead_time_insufficient'=>$late],
            'formulas'=>['position'=>'available + total_certified_incoming','projected'=>'available - consumption_before_receipt + incoming_by_target','target'=>'horizon_demand + safety','need'=>'max(0, target - projected)','minimum'=>'explicit_or_calibrated_safety','reorder'=>'demand_over_full_lead_time + safety; unknown without temporal evidence','packages'=>'ceil(max(need / factor, minimum) / multiple) * multiple; zero need => zero'],
            'assumptions'=>$assumptions,'uncertainty'=>array_values(array_unique($issues)),'warnings'=>array_values(array_unique($warnings))]);
    }
}
