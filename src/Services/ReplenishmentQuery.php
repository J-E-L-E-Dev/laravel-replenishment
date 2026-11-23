<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\{ReplenishmentOptions,ReplenishmentPlanData};
use Asisnet\Replenishment\Models\SupplierPresentation;
use DateTimeImmutable;
use InvalidArgumentException;

/** Coordina consultas existentes y configuración propia sin escrituras implícitas. */
final class ReplenishmentQuery
{
    /**
     * Recibe contratos y motores aprobados.
     * @param AdapterRegistry $adapters Fuentes del consumidor.
     * @param AvailabilityQuery $availability Disponibilidad normalizada.
     * @param ForecastQuery $forecast Pronósticos mensuales existentes.
     * @param SupplierLeadTimeQuery $leadTime Plazos y precedencia comercial.
     * @param ReplenishmentCalculator $calculator Pendientes y operaciones exactas.
     * @param ReplenishmentPlanner $planner Planificador puro.
     */
    public function __construct(private AdapterRegistry $adapters,private AvailabilityQuery $availability,private ForecastQuery $forecast,
        private SupplierLeadTimeQuery $leadTime,private ReplenishmentCalculator $calculator,private ReplenishmentPlanner $planner) {}
    /**
     * Consulta un escenario acotado; las hipótesis no modifican configuración almacenada.
     * @param string $productId Producto.
     * @param string $supplierId Proveedor.
     * @param string $locationId Destino lógico.
     * @param ReplenishmentOptions $options Escenario explícito.
     * @param DateTimeImmutable $historyFrom Inicio mensual del histórico, máximo tres años en CLI por defecto.
     * @return ReplenishmentPlanData Resultado auditable.
     * @throws InvalidArgumentException Si no existe producto, se excede horizonte o faltan parámetros coherentes.
     * @throws \Throwable Si falla una lectura técnica indispensable; la CLI devuelve error sin escrituras.
     */
    public function analyze(string $productId,string $supplierId,string $locationId,ReplenishmentOptions $options,DateTimeImmutable $historyFrom): ReplenishmentPlanData
    {
        $product=$this->adapters->resolve('products')->find($productId);
        $availability=$this->availability->forProduct($productId,$locationId);
        if($product===null||$availability===null){throw new InvalidArgumentException('El producto solicitado no existe.');}
        $origin=$options->asOf->modify('first day of this month');$forecast=null;$warnings=[];
        if($options->monthlyDemand===null){
            $end=$options->targetReceipt->modify('first day of this month')->modify('+'.$options->horizon.' months');
            $months=((int)$end->format('Y')-(int)$origin->format('Y'))*12+(int)$end->format('m')-(int)$origin->format('m');
            if($months>6){$warnings[]='El horizonte excede los seis meses del pronóstico disponible; la demanda permanece desconocida.';}
            else{$forecast=$this->forecast->analyze($productId,$locationId,$historyFrom,$origin,$months)->audit();}
        }
        $lead=['product_id'=>$productId,'supplier_id'=>$supplierId,'location_id'=>$locationId,'lead_time_days'=>null,'source'=>'unknown','quality'=>'unknown'];
        if($options->leadTimeDays===null){$lead=$this->leadTime->analyze($productId,$supplierId,$locationId,$historyFrom,$options->asOf)->resolution;}
        else{$warnings[]='Se usa plazo de escenario; no se consultó ni sustituyó el plazo comercial almacenado.';}
        $incoming=$this->calculator->incoming($this->adapters->resolve('purchases'),$productId,$locationId,$options->asOf,$options->targetReceipt);
        $presentation=null;
        if($options->presentationId!==null){
            try{$model=SupplierPresentation::find($options->presentationId);if($model!==null){$presentation=$this->presentation($model);}}
            catch(\Illuminate\Database\QueryException $error){$warnings[]='No se pudo leer la presentación propia; no se ejecutaron migraciones.';}
        }
        $lead['warnings']=array_merge($lead['warnings']??[],$warnings);
        return $this->planner->plan($availability,$supplierId,$product->stockUnit,$options,$forecast,$lead,$incoming,$presentation);
    }
    /**
     * Verifica relación, catálogo y revisión más reciente de la misma unidad comercial.
     * @param SupplierPresentation $model Revisión explícita existente.
     * @return array Instantánea con vigencia comprobada.
     * @throws \Throwable Si las relaciones propias son inconsistentes o la conexión falla.
     */
    public function presentation(SupplierPresentation $model): array
    {
        $relation=$model->productSupplier()->firstOrFail();$unit=$model->purchaseUnit()->firstOrFail();
        $latest=SupplierPresentation::query()->where('product_supplier_id',$model->product_supplier_id)->where('purchase_unit_id',$model->purchase_unit_id)->max('revision');
        return $model->snapshot()+['active'=>$model->active&&$unit->active,'relation_active'=>$relation->active,'current_revision'=>$model->revision===(int)$latest];
    }
}
