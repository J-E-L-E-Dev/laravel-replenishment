<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Contracts\SupplierProductSource;
use Asisnet\Replenishment\Models\{SupplierImport,ProductSupplier};
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Vista previa y ejecución reanudable en lotes atómicos, sin sobrescribir relaciones existentes. */
final class SupplierImportService
{
    /**
     * Recibe persistencia propia y validación comercial reutilizable.
     * @param PersistenceConnection $connection Destino transaccional.
     * @param CommercialConfiguration $configuration Validación de maestros y altas.
     * @param AdapterRegistry $adapters Identidades de productos.
     */
    public function __construct(private PersistenceConnection $connection,private CommercialConfiguration $configuration,private AdapterRegistry $adapters) {}
    /**
     * Abre una vista previa durable con parámetros explícitos y actor auditable.
     * @param string $supplier Código recibido.
     * @param array $options Plazo, preferencia, estado y notas; nunca presentaciones.
     * @param string $actor Identidad del operador.
     * @return SupplierImport Operación sin relaciones creadas.
     * @throws InvalidArgumentException Si los parámetros o el proveedor no son válidos.
     */
    public function start(string $supplier,array $options,string $actor): SupplierImport
    {
        if(array_diff(array_keys($options),['manual_lead_time_days','is_preferred','active','notes'])){throw new InvalidArgumentException('La carga sólo admite plazo, preferencia, estado y notas.');}
        $options=$this->configuration->validate('suppliers',$options);
        foreach(['active','is_preferred'] as $key){if(!array_key_exists($key,$options)){throw new InvalidArgumentException('Indique expresamente estado y preferencia.');}}
        if($actor===''||mb_strlen($actor)>100){throw new InvalidArgumentException('Indique un actor auditable.');}
        $supplier=$this->configuration->supplier($supplier)->id;
        return SupplierImport::create(['id'=>(string)Str::uuid(),'supplier_id'=>$supplier,'actor_id'=>$actor,'status'=>'previewing','version'=>0,'cursor'=>null,'options'=>$options,'totals'=>$this->emptyTotals()]);
    }
    /**
     * Confirma sólo una vista previa finalizada, sin ejecutar todavía relaciones.
     * @param string $id UUID de operación.
     * @param int $version Versión vista por el operador, para evitar confirmaciones obsoletas.
     * @return SupplierImport Estado listo; repetir la confirmación no reinicia nada.
     * @throws LogicException Si aún no terminó la vista previa.
     */
    public function confirm(string $id,int $version): SupplierImport
    {
        return $this->connection->transaction(function()use($id,$version):SupplierImport{
            $run=SupplierImport::query()->lockForUpdate()->findOrFail($id);
            if(in_array($run->status,['running','completed'],true)){return $run;}
            if($run->status!=='previewed'||$run->version!==$version){throw new LogicException('Complete o recargue la vista previa antes de confirmar.');}
            $run->preview=$run->totals;$run->totals=$this->emptyTotals();$run->status='running';$run->cursor=null;$run->version++;$run->save();return $run;
        });
    }
    /**
     * Procesa como máximo cien candidatos y confirma cursor, conteos y altas juntos.
     * @param string $id Operación auditable.
     * @param int $version Versión esperada; una petición repetida sólo obtiene el estado vigente.
     * @param SupplierProductSource $source Fuente externa de lectura por bloques.
     * @return SupplierImport Resultado, omitidos, conflictos y errores del lote.
     * @throws \Throwable Si falla infraestructura, todo el lote revierte y puede reintentarse.
     */
    public function advance(string $id,int $version,SupplierProductSource $source): SupplierImport
    {
        return $this->connection->transaction(function()use($id,$version,$source):SupplierImport{
            $run=SupplierImport::query()->lockForUpdate()->findOrFail($id);
            if($run->version!==$version||!in_array($run->status,['previewing','running'],true)){return $run;}
            $this->configuration->supplier($run->supplier_id);
            $ids=$source->productsFor($run->supplier_id,$run->cursor,100);
            if(count($ids)>100||count(array_unique($ids))!==count($ids)){throw new LogicException('La fuente devolvió un bloque inválido o duplicado.');}
            $totals=$run->totals;$results=[];
            foreach($ids as $product){
                if(!is_string($product)||$product===''){throw new LogicException('La fuente devolvió una identidad sin cursor verificable.');}
                $totals['found']++;
                try{
                    if($this->adapters->resolve('products')->find($product)?->id!==$product){throw new InvalidArgumentException('Identidad de producto no verificable.');}
                    $relations=ProductSupplier::where('product_id',$product)->lockForUpdate()->get();
                    if($relations->contains(fn($relation)=>$relation->supplier_id===$run->supplier_id)){$state='existing';}
                    elseif(($run->options['is_preferred']??false)&&$relations->contains('is_preferred',true)){$state='conflicts';}
                    else{
                        $state=$run->status==='previewing'?'new':'imported';
                        if($state==='imported'){$created=$this->configuration->create('suppliers',$run->options+['product_id'=>$product,'supplier_id'=>$run->supplier_id]);}
                    }
                    $totals[$state]++;$results[]=['product_id'=>$product,'status'=>$state,'relation_id'=>$state==='imported'?$created->id:null];
                }catch(InvalidArgumentException $error){$totals['errors']++;$results[]=['product_id'=>$product,'status'=>'errors','message'=>$error->getMessage()];}
            }
            $run->last_batch=['from'=>$run->cursor,'rows'=>$results];$run->totals=$totals;
            if($ids!==[]){$last=end($ids);if($last===$run->cursor){throw new LogicException('La fuente no avanzó su cursor.');}$run->cursor=$last;}
            if(count($ids)<100){$run->status=$run->status==='previewing'?'previewed':'completed';}
            $run->version++;$run->save();return $run;
        });
    }
    /**
     * Crea conteos estructurales, no cantidades comerciales desconocidas.
     * @return array<string,int> Categorías separadas de resultado.
     */
    private function emptyTotals(): array {return ['found'=>0,'new'=>0,'existing'=>0,'conflicts'=>0,'errors'=>0,'imported'=>0];}
}
