<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\SupplierLeadTimeData;
use Asisnet\Replenishment\DTO\SupplierLeadTimeOptions;
use Asisnet\Replenishment\DTO\SupplierLeadTimeResolutionData;
use Asisnet\Replenishment\Models\ProductSupplier;
use Asisnet\Replenishment\Models\Policy;
use InvalidArgumentException;

/** Resuelve una sola fuente explícita, sin promediar contratos manuales con muestras históricas. */
final class SupplierLeadTimeResolver
{
    /**
     * Prioriza configuración manual activa, historial certificado utilizable y política explícita, en ese orden.
     * @param SupplierLeadTimeData $history Diagnóstico del destino solicitado.
     * @param SupplierLeadTimeOptions $options Tipo de plazo histórico elegible.
     * @param ProductSupplier|null $relation Relación propia ya leída; su manual es general, no por sucursal.
     * @param Policy|null $policy Política elegida explícitamente por el consumidor, nunca la primera disponible.
     * @param int|null $defaultDays Predeterminado configurado expresamente; NULL no significa cero.
     * @param array $warnings Limitaciones de disponibilidad de configuración.
     * @return SupplierLeadTimeResolutionData Plazo y procedencia, sin certificar compromisos pendientes.
     * @throws InvalidArgumentException Si el alcance o los valores manuales no son válidos.
     */
    public function resolve(SupplierLeadTimeData $history,SupplierLeadTimeOptions $options,?ProductSupplier $relation=null,?Policy $policy=null,?int $defaultDays=null,array $warnings=[]): SupplierLeadTimeResolutionData
    {
        if($relation!==null&&($relation->product_id!==$history->productId||$relation->supplier_id!==$history->supplierId)){throw new InvalidArgumentException('La relación producto-proveedor no corresponde al diagnóstico.');}
        if($defaultDays!==null&&$defaultDays<0){throw new InvalidArgumentException('El plazo predeterminado no puede ser negativo.');}
        if($policy?->default_lead_time_days!==null&&$policy->default_lead_time_days<0){throw new InvalidArgumentException('El plazo de la política no puede ser negativo.');} $manual=$relation?->manual_lead_time_days;
        if($manual!==null&&$manual<0){throw new InvalidArgumentException('El plazo manual no puede ser negativo.');}
        $metric=$history->summary[$options->historicalMeasure==='complete_receipt'?'verified_complete_receipt':'verified_first_receipt'];
        $days=null;$source='unknown';$quality='unknown';$scope=null;$measure=null;$policyId=null;
        if($relation!==null&&$relation->active&&$manual!==null){$days=(string)$manual;$source='manual';$quality='explicit_configuration';$scope='product_supplier_general';$measure='configured_commercial_lead_time';}
        elseif($history->summary['reading_status']==='complete'&&$metric['quality']==='usable'){$days=$metric['average_days'];$source='historical';$quality='usable';$scope='product_supplier_location';$measure=$options->historicalMeasure;}
        elseif($policy!==null&&$policy->active&&$policy->default_lead_time_days!==null){$days=(string)$policy->default_lead_time_days;$source='policy_default';$quality='explicit_configuration';$scope='explicit_policy';$measure='configured_commercial_lead_time';$policyId=$policy->getKey();}
        elseif($defaultDays!==null){$days=(string)$defaultDays;$source='policy_default';$quality='explicit_configuration';$scope='configured_default';$measure='configured_commercial_lead_time';}
        if($source==='manual'){$warnings[]='El plazo manual es general para la relación activa; no mide una sucursal ni tiene confianza estadística.';}
        if($relation!==null&&!$relation->active){$warnings[]='La configuración manual de una relación inactiva no se utiliza.';}
        if($source==='historical'&&$measure==='first_receipt'){$warnings[]='El plazo elegido sólo alcanza la primera recepción, no la entrega completa.';}
        if($source==='unknown'){$warnings[]='No existe un plazo operativo suficientemente respaldado o explícitamente configurado.';}
        $warnings[]='La vigencia manual depende de mantener/revocar explícitamente el valor y la relación activa; updated_at no acredita revisión comercial.';
        $warnings[]='No se confirma mercancía pendiente ni se generan pedidos con esta resolución.';
        return new SupplierLeadTimeResolutionData($history,['product_id'=>$history->productId,'supplier_id'=>$history->supplierId,'product_supplier_id'=>$relation?->getKey(),
            'location_id'=>$history->locationId,'evaluated_at'=>$history->evaluatedAt->format(DATE_ATOM),'lead_time_days'=>$days,'source'=>$source,'source_scope'=>$scope,
            'measure'=>$measure,'quality'=>$quality,'policy_id'=>$policyId,'historical_sample_size'=>$metric['observations'],'historical_deviation_days'=>$metric['deviation_days'],
            'selected_source_sample_size'=>$source==='historical'?$metric['observations']:null,'selected_source_deviation_days'=>$source==='historical'?$metric['deviation_days']:null,
            'confidence'=>null,'warnings'=>array_values(array_unique($warnings))]);
    }
}
