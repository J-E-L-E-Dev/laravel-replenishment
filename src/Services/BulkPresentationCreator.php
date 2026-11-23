<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\{ProductSupplier,PurchaseUnit,SupplierPresentation};
use InvalidArgumentException;

/** Crea presentaciones comunes sólo con unidades base verificadas; nunca sustituye revisiones existentes. */
final class BulkPresentationCreator
{
    /**
     * Recibe reglas existentes y la conexión transaccional propia.
     * @param CommercialConfiguration $configuration Administración validada.
     * @param PersistenceConnection $connection Destino propio, independiente del ERP.
     * @param AdapterRegistry $adapters Maestros de lectura.
     */
    public function __construct(private CommercialConfiguration $configuration,private PersistenceConnection $connection,private AdapterRegistry $adapters) {}
    /**
     * Verifica todo el lote antes de crear; cualquier conflicto impide las altas del conjunto.
     * @param array<int|string> $relationIds Entre una y cien identidades distintas.
     * @param array $common Unidad, factor, mínimo, múltiplo y estado explícitos, con decimales como cadenas.
     * @return array Solicitadas, creadas, existentes, conflictos y errores; sin mutar históricos.
     * @throws InvalidArgumentException Si los atributos son inválidos; ninguna alta parcial queda confirmada.
     * @throws \Throwable Si falla una fuente o la transacción, que revierte el lote completo.
     */
    public function create(array $relationIds,array $common): array
    {
        if(count($relationIds)<1||count($relationIds)>100){throw new InvalidArgumentException('Seleccione entre una y cien relaciones.');}
        foreach($relationIds as $id){if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id)||(int)$id>2147483647){throw new InvalidArgumentException('Las identidades de relación no son válidas.');}}
        $ids=array_map('intval',$relationIds);
        if(count(array_unique($ids))!==count($ids)){throw new InvalidArgumentException('No repita relaciones en el lote.');}
        $fields=['purchase_unit_id','base_units_per_package','minimum_packages','package_multiple','active'];
        if(array_diff(array_keys($common),$fields)||array_diff($fields,array_keys($common))){throw new InvalidArgumentException('Indique sólo unidad comercial, factor, mínimo, múltiplo y estado.');}
        $common=$this->configuration->validate('presentations',$common);sort($ids);
        return $this->connection->transaction(function()use($ids,$common):array{
            $report=['requested'=>count($ids),'created'=>[],'existing'=>[],'conflicts'=>[],'errors'=>[],'status'=>'completed'];
            $relations=ProductSupplier::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $unit=PurchaseUnit::whereKey($common['purchase_unit_id'])->lockForUpdate()->first();
            if($unit===null||!$unit->active){$report['errors'][]=['message'=>'La unidad comercial no existe o está inactiva.'];}
            $units=[];
            foreach($ids as $id){
                $relation=$relations->get($id);
                if($relation===null){$report['errors'][]=['relation_id'=>$id,'message'=>'La relación no existe.'];continue;}
                if(!$relation->active){$report['conflicts'][]=['relation_id'=>$id,'message'=>'La relación está inactiva.'];continue;}
                $product=$this->adapters->resolve('products')->find($relation->product_id);
                $supplier=$this->adapters->resolve('suppliers')->find($relation->supplier_id);
                if($product?->id!==$relation->product_id||$supplier?->id!==$relation->supplier_id||trim($product->stockUnit)===''){
                    $report['errors'][]=['relation_id'=>$id,'message'=>'Producto, proveedor o unidad base no verificables.'];continue;
                }
                $units[$id]=$product->stockUnit;
                if(SupplierPresentation::where('product_supplier_id',$id)->where('purchase_unit_id',$common['purchase_unit_id'])->exists()){$report['existing'][]=$id;}
            }
            if(count(array_unique(array_values($units),SORT_STRING))>1){$report['conflicts'][]=['message'=>'Las unidades base son incompatibles. Separe la selección por unidad y verifique cada equivalencia.','units'=>$units];}
            if($report['errors']||$report['conflicts']){$report['status']='blocked';return $report;}
            foreach($ids as $id){if(in_array($id,$report['existing'],true)){continue;}
                $row=$this->configuration->create('presentations',$common+['product_supplier_id'=>$id]);
                $report['created'][]=['relation_id'=>$id,'presentation_id'=>$row->id];
            }
            return $report;
        });
    }
}
