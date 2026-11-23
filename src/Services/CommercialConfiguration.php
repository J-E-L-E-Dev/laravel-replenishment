<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\{PurchaseUnit,ProductSupplier,SupplierPresentation,Policy,ReplenishmentModel};
use Asisnet\Replenishment\Casts\ExactDecimal;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use LogicException;

/** Administración comercial reutilizable, sin maestros duplicados, inferencias ERP ni eliminación física. */
final class CommercialConfiguration
{
    /** @var array<string,class-string<ReplenishmentModel>> Recursos explícitamente administrables. */
    private const MODELS=['units'=>PurchaseUnit::class,'suppliers'=>ProductSupplier::class,'presentations'=>SupplierPresentation::class,'policies'=>Policy::class];
    /**
     * Recibe destino propio y contratos de maestros.
     * @param PersistenceConnection $connection Conexión transaccional.
     * @param AdapterRegistry $adapters Validadores externos de sólo lectura.
     */
    public function __construct(private PersistenceConnection $connection,private AdapterRegistry $adapters) {}
    /**
     * Lista lotes acotados por identidad interna, sin cargar todo el catálogo.
     * @param string $resource Recurso permitido.
     * @param int $after Identidad exclusiva para paginación.
     * @param int $limit Máximo de cien registros.
     * @return Collection Modelos propios, incluidas revisiones históricas.
     * @throws InvalidArgumentException Si el recurso o límites son inválidos.
     */
    public function list(string $resource,int $after=0,int $limit=50): Collection
    {
        if($after<0||$limit<1||$limit>100){throw new InvalidArgumentException('Use un cursor no negativo y un límite entre 1 y 100.');}
        return $this->model($resource)::query()->where('id','>',$after)->orderBy('id')->limit($limit)->get();
    }
    /**
     * Consulta una identidad sin seleccionar otra presentación.
     * @param string $resource Recurso.
     * @param int $id Identidad interna.
     * @return ReplenishmentModel Modelo.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si no existe.
     */
    public function find(string $resource,int $id): ReplenishmentModel {return $this->model($resource)::findOrFail($id);}
    /**
     * Crea configuración expresa, validando los maestros antes de escribir.
     * @param string $resource Recurso.
     * @param array $input Atributos explícitos.
     * @param string|null $actor Actor autenticado; NULL identifica una llamada interna sin usuario, nunca un actor inventado.
     * @return ReplenishmentModel Nuevo registro.
     * @throws InvalidArgumentException Si hay campos o identidades externas inválidos.
     * @throws LogicException Si ya existe una presentación de esa unidad y relación.
     * @throws \Throwable Si falla persistencia o lectura externa; no publica cambios parciales.
     */
    public function create(string $resource,array $input,?string $actor=null): ReplenishmentModel
    {
        $data=$this->validate($resource,$input);
        if($resource==='suppliers'){$data['supplier_id']=$this->validateMasters($data['product_id']??null,$data['supplier_id']??null);}
        return $this->connection->transaction(function()use($resource,$data,$actor):ReplenishmentModel{
            if($resource==='presentations'){
                $relation=ProductSupplier::query()->lockForUpdate()->findOrFail($data['product_supplier_id']??0);
                PurchaseUnit::findOrFail($data['purchase_unit_id']??0);
                if(SupplierPresentation::where('product_supplier_id',$relation->id)->where('purchase_unit_id',$data['purchase_unit_id'])->exists()){
                    throw new LogicException('La presentación ya existe; modifique su revisión vigente para registrar cambios.');}
                $data['revision']=1;
            }
            $model=new ($this->model($resource))($data);
            if($resource==='policies' && isset($data['demand_settings'])){$model->continuity_authorized_by=$data['demand_settings']['continuity']==='assumed'?$actor:null;}
            if($resource==='policies'){$model->forceFill(['created_by'=>$actor,'updated_by'=>$actor]);}
            $model->save();if($resource==='policies'){$this->policyEvent($model,$actor,'create',null);}
            return $model;
        });
    }
    /**
     * Modifica descripción/estado o añade revisión cuando cambian conversiones comerciales.
     * @param string $resource Recurso.
     * @param int $id Identidad exacta seleccionada.
     * @param array $input Cambios explícitos; NULL revoca un plazo manual.
     * @param string|null $actor Actor autenticado del transporte.
     * @return ReplenishmentModel Registro actualizado o nueva revisión.
     * @throws LogicException Si se intenta cambiar identidad o revisar una versión obsoleta.
     * @throws \Throwable Si fallan las reglas o la transacción.
     */
    public function update(string $resource,int $id,array $input,?string $actor=null): ReplenishmentModel
    {
        $data=$this->validate($resource,$input);
        if($resource==='suppliers'&&isset($data['supplier_id'])){$data['supplier_id']=$this->supplier($data['supplier_id'])->id;}
        return $this->connection->transaction(function()use($resource,$id,$data,$actor):ReplenishmentModel{
            if($resource==='presentations'){
                $initial=SupplierPresentation::findOrFail($id);
                ProductSupplier::query()->lockForUpdate()->findOrFail($initial->product_supplier_id);
            }
            $model=$this->model($resource)::query()->lockForUpdate()->findOrFail($id);
            $before=$model->toArray();
            if($resource==='policies' && isset($data['demand_settings'])){$model->continuity_authorized_by=$data['demand_settings']['continuity']==='assumed'?$actor:null;}
            $fixed=match($resource){'units'=>['code'],'suppliers'=>['product_id','supplier_id'],'presentations'=>['product_supplier_id','purchase_unit_id'],default=>[]};
            foreach($fixed as $field){if(array_key_exists($field,$data)&&$data[$field]!==$model->$field){throw new LogicException('No se puede cambiar '.$field.'; cree una configuración distinta.');}}
            if($resource==='suppliers'){$this->validateMasters($model->product_id,$model->supplier_id);}
            if($resource==='presentations'){
                $changed=false;foreach(['base_units_per_package','minimum_packages','package_multiple'] as $field){if(isset($data[$field])&&$data[$field]!==$model->$field){$changed=true;}}
                if(($changed||($data['active']??false))&&!$this->isCurrent($model)){throw new LogicException('La revisión seleccionada ya no es vigente; consulte la más reciente de la misma presentación.');}
                if($changed){return SupplierPresentation::create(array_replace($model->only(['product_supplier_id','purchase_unit_id','base_units_per_package','minimum_packages','package_multiple','active']),$data,['revision'=>$model->revision+1]));}
            }
            $model->fill($data);
            if($resource==='policies'){$model->forceFill(['updated_by'=>$actor]);}
            $model->save();if($resource==='policies'){$action=($before['active']??false)!==$model->active?($model->active?'activate':'deactivate'):'update';$this->policyEvent($model,$actor,$action,$before);}return $model;
        });
    }
    /**
     * Comprueba vigencia sólo dentro de la misma relación y unidad, incluso si la última revisión está inactiva.
     * @param SupplierPresentation $presentation Revisión solicitada.
     * @return bool Verdadero si no fue sustituida por otra revisión.
     */
    public function isCurrent(SupplierPresentation $presentation): bool
    {
        return $presentation->revision===(int)SupplierPresentation::where('product_supplier_id',$presentation->product_supplier_id)->where('purchase_unit_id',$presentation->purchase_unit_id)->max('revision');
    }
    /**
     * Valida tipos antes de los casts Eloquent y conserva restricciones comerciales del modelo.
     * @param string $resource Recurso.
     * @param array $input Atributos recibidos.
     * @return array Datos exactos, sin flotantes ni valores comerciales predeterminados.
     * @throws InvalidArgumentException Si hay atributos desconocidos, flotantes o tipos inválidos.
     */
    public function validate(string $resource,array $input): array
    {
        $class=$this->model($resource);$allowed=(new $class())->getFillable();
        if($resource==='presentations'){$allowed=array_values(array_diff($allowed,['revision']));}
        foreach($input as $key=>$value){
            if(!in_array($key,$allowed,true)){throw new InvalidArgumentException('Atributo no administrable: '.$key.'.');}
            if($key==='demand_settings'){
                if(!is_array($value)){throw new InvalidArgumentException('Defina las opciones de demanda como un objeto.');}
                $consent=$value['continuity_consent']??false;
                unset($value['continuity_consent']);
                if(($value['continuity']??'strict')==='assumed' && $consent!==true){throw new InvalidArgumentException('Confirme expresamente la continuidad asumida de la unidad.');}
                $input[$key]=PolicyDemandSettings::normalize($value);
            }elseif(in_array($key,['active','is_preferred'],true)){
                if(!in_array($value,[true,false,0,1,'0','1'],true)){throw new InvalidArgumentException($key.' debe ser booleano.');}$input[$key]=(bool)$value;
            }elseif(in_array($key,['base_units_per_package','minimum_packages','package_multiple','service_level'],true)){
                if($value===null&&$resource==='policies'){continue;}$input[$key]=ExactDecimal::normalize($value,$key==='service_level'?9:24,6);
            }elseif(in_array($key,['product_supplier_id','purchase_unit_id','manual_lead_time_days','analysis_days','target_coverage_days','default_lead_time_days','minimum_history_days','seasonal_minimum_years','forecast_horizon_days'],true)){
                if($value===null&&!in_array($key,['product_supplier_id','purchase_unit_id'],true)){continue;}
                if((!is_int($value)&&!is_string($value))||!preg_match('/^\d{1,10}$/D',(string)$value)||(int)$value>2147483647){throw new InvalidArgumentException($key.' debe ser un entero no negativo de 32 bits.');}$input[$key]=(int)$value;
            }else{
                if($value===null&&in_array($key,['description','notes'],true)){continue;}
                if(!is_string($value)||mb_strlen($value)>10000){throw new InvalidArgumentException($key.' debe ser texto válido; conserve los ceros iniciales de las identidades.');}
            }
        }
        return $input;
    }
    /**
     * Verifica el producto exacto y obtiene el proveedor canónico mediante contratos de sólo lectura.
     * @param string|null $product Producto externo.
     * @param string|null $supplier Proveedor externo.
     * @return string Identificador canónico del proveedor verificado.
     * @throws InvalidArgumentException Si faltan identidades o no existen en sus fuentes.
     * @throws \RuntimeException Si falla una fuente; no se transforma en ausencia.
     */
    private function validateMasters(?string $product,?string $supplier): string
    {
        if($product===null||$supplier===null||$product===''||$supplier===''){throw new InvalidArgumentException('Indique producto y proveedor externos.');}
        if($this->adapters->resolve('products')->find($product)?->id!==$product){throw new InvalidArgumentException('El producto externo no existe o su identidad no coincide.');}
        return $this->supplier($supplier)->id;
    }
    /**
     * Normaliza la entrada de proveedor y devuelve la identidad canónica del maestro.
     * @param string $code Código introducido, con mayúsculas y espacios exteriores tolerados.
     * @return \Asisnet\Replenishment\DTO\SupplierData Maestro comprobado.
     * @throws InvalidArgumentException Si no existe el proveedor; los errores de fuente se propagan.
     */
    public function supplier(string $code): \Asisnet\Replenishment\DTO\SupplierData
    {
        $code=mb_strtoupper(trim($code));
        if($code===''){throw new InvalidArgumentException('Indique un proveedor.');}
        return $this->adapters->resolve('suppliers')->find($code)??throw new InvalidArgumentException('El proveedor externo no existe.');
    }
    /**
     * Sugiere únicamente el último plazo manual activo del mismo proveedor.
     * @param string $supplier Identidad canónica previamente comprobada.
     * @return array|null Plazo y relación de procedencia; NULL significa que no hay sugerencia.
     */
    public function suggestedLeadTime(string $supplier): ?array
    {
        $row=ProductSupplier::where('supplier_id',$supplier)->where('active',true)->whereNotNull('manual_lead_time_days')->orderByDesc('created_at')->orderByDesc('id')->first();
        return $row===null?null:['days'=>$row->manual_lead_time_days,'relation_id'=>$row->id,'source'=>'Último plazo registrado para este proveedor'];
    }
    /**
     * Resuelve una lista cerrada de modelos propios.
     * @param string $resource Recurso.
     * @return class-string<ReplenishmentModel> Clase permitida.
     * @throws InvalidArgumentException Si el recurso no existe.
     */
    private function model(string $resource): string
    {
        return self::MODELS[$resource]??throw new InvalidArgumentException('Recurso comercial desconocido.');
    }
    /** Registra antes/después dentro de la transacción comercial. @param Policy $policy Política persistida. @param string|null $actor Actor externo sin FK a usuarios. @param string $action Decisión. @param array|null $before Estado anterior. @return void */
    private function policyEvent(Policy $policy,?string $actor,string $action,?array $before): void
    {
        if($actor!==null&&(trim($actor)===''||strlen($actor)>100)){throw new InvalidArgumentException('Actor de política inválido.');}
        \Asisnet\Replenishment\Models\PolicyEvent::create(['policy_id'=>$policy->id,'actor_id'=>$actor,'action'=>$action,'payload'=>['before'=>$before,'after'=>$policy->toArray()]]);
    }
}
