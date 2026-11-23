<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\{PurchaseProposal,PurchaseProposalItem,ProposalEvent,ProductSupplier,SupplierPresentation};
use Asisnet\Replenishment\DTO\ReplenishmentPlanData;
use Brick\Math\{BigDecimal,RoundingMode};
use InvalidArgumentException;
use LogicException;

/** Gestiona propuestas internas, evidencia por línea y transiciones atómicas; nunca emite órdenes ERP. */
final class PurchaseProposalService
{
    /** Recibe infraestructura propia y contratos existentes. @param PersistenceConnection $connection Conexión propia. @param AdapterRegistry $adapters Maestros normalizados. @param ReplenishmentCalculator $calculator Conversión existente. @param ReplenishmentQuery $query Verificación de presentación. @param ReplenishmentStore $store Publicación histórica explícita. */
    public function __construct(private PersistenceConnection $connection,private AdapterRegistry $adapters,private ReplenishmentCalculator $calculator,private ReplenishmentQuery $query,private ReplenishmentStore $store) {}

    /** Crea un borrador idempotente sin copiar automáticamente líneas históricas. @param array $data Proveedor, ubicación, notas y antecedente opcional. @param string $actor Identidad autenticada. @param string $request UUID de operación. @return PurchaseProposal Borrador. @throws InvalidArgumentException Entradas inválidas. @throws LogicException Reintento incompatible. */
    public function create(array $data,string $actor,string $request): PurchaseProposal
    {
        if(!is_string($data['supplier_id']??null)||trim($data['supplier_id'])===''||mb_strlen($data['supplier_id'])>100){throw new InvalidArgumentException('Indique un proveedor válido como identidad textual.');}
        $hash=$this->hash('create',$data,$actor,$request);$retry=$this->retry($request,$hash);if($retry){return $retry;}
        return $this->connection->transaction(function()use($data,$actor,$request,$hash):PurchaseProposal{
            $supplier=$this->adapters->resolve('suppliers')->find($data['supplier_id']);if(!$supplier){throw new InvalidArgumentException('El proveedor no existe en la fuente.');}
            if(!is_string($data['location_id']??null)||trim($data['location_id'])===''){throw new InvalidArgumentException('Indique una ubicación lógica explícita.');}
            if(isset($data['derived_from_id'])){$parent=PurchaseProposal::findOrFail($data['derived_from_id']);if(!in_array($parent->status,['approved','rejected','cancelled'],true)){throw new InvalidArgumentException('Sólo se deriva un histórico cerrado.');}}
            $p=PurchaseProposal::create(['supplier_id'=>$supplier->id,'location_id'=>$data['location_id'],'created_by'=>$actor,'creation_key'=>$request,'version'=>1,'notes'=>$data['notes']??null,'derived_from_id'=>$data['derived_from_id']??null]);
            $this->event($p,$request,$hash,$actor,'create',null,['proposal'=>$p->toArray()]);return $p;
        });
    }

    /** Ejecuta un comando versionado; las capacidades son del adaptador autenticado, nunca del cuerpo HTTP. @param int $id Propuesta. @param int $version Versión esperada. @param string $action Acción explícita. @param array $data Datos de línea o transición. @param string $actor Actor real. @param string $request UUID idempotente. @param bool $canApprove Capacidad autenticada de aprobación/rechazo. @param ReplenishmentPlanData|null $plan Resultado recalculado en servidor, no JSON de Vue. @param int|null $generationId Contexto interno de construcción por lotes; nunca se acepta desde HTTP. @return PurchaseProposal Estado vigente. @throws LogicException Conflicto de versión, estado o idempotencia. @throws InvalidArgumentException Reglas comerciales inválidas. */
    public function execute(int $id,int $version,string $action,array $data,string $actor,string $request,bool $canApprove=false,?ReplenishmentPlanData $plan=null,?int $generationId=null): PurchaseProposal
    {
        $hash=$this->hash($action,['id'=>$id,'version'=>$version,'data'=>$data],$actor,$request);if($retry=$this->retry($request,$hash)){return $retry;}
        return $this->connection->transaction(function()use($id,$version,$action,$data,$actor,$request,$hash,$canApprove,$plan,$generationId):PurchaseProposal{
            $p=PurchaseProposal::query()->lockForUpdate()->findOrFail($id);
            if($retry=$this->retry($request,$hash)){return $retry;}
            $building=null;
            if($this->connection->connection()->getSchemaBuilder()->hasTable('replenishment_generations')){
                $building=\Asisnet\Replenishment\Models\ProposalGeneration::where('proposal_id',$id)->where('status','saving')->first();
                if($building&&($generationId!==$building->id||!in_array($action,['add','adjust','cancel'],true))){throw new LogicException('La propuesta se está construyendo por lotes; termine su generación antes de editarla o enviarla a revisión.');}
            }
            if ($generationId !== null && $building?->id !== $generationId) {
                throw new LogicException('La generación no está construyendo esta propuesta.');
            }
            if($p->version!==$version){throw new LogicException('La propuesta cambió. Recargue antes de confirmar.');}
            if(!$p->creation_key){throw new LogicException('Propuesta anterior sin auditoría conciliada; no se adopta automáticamente.');}
            if(in_array($action,['adjust','remove'],true)&&(!isset($data['item_id'])||!is_numeric($data['item_id']))){throw new InvalidArgumentException('Seleccione una línea de la propuesta.');}
            if(in_array($action,['add','adjust'],true)&&empty($data['presentation_id'])){throw new InvalidArgumentException('Seleccione una presentación explícita.');}
            if($action==='add'&&(!is_string($data['product_id']??null)||trim($data['product_id'])==='')){throw new InvalidArgumentException('Seleccione un producto mediante identidad textual.');}
            unset($data['_generation_reference']);
            if($generationId!==null&&$action==='add'){$sourceRow=\Asisnet\Replenishment\Models\ProposalGenerationItem::where('generation_id',$generationId)->where('product_id',$data['product_id'])->firstOrFail();$data['_generation_reference']=['generation_id'=>$generationId,'item_id'=>$sourceRow->id];}
            $before=$this->auditSnapshot($p,$generationId,$data);$warnings=[];
            if(in_array($action,['add','adjust','remove','notes'],true)){
                if($p->status!=='draft'){throw new LogicException('Sólo se editan propuestas en borrador.');}
                if($action==='notes'){$p->notes=$data['notes']??null;}
                elseif($action==='remove'){$line=$p->items()->whereNull('removed_at')->findOrFail($data['item_id']);$this->reason($data);$line->removed_at=now();$line->save();}
                else{$warnings=$this->line($p,$action,$data,$plan);}
            }else{
                $allowed=['submit'=>['draft','in_review'],'approve'=>['in_review','approved'],'reject'=>['in_review','rejected'],'return'=>['in_review','draft'],'cancel'=>[$p->status,'cancelled']];
                if(!isset($allowed[$action])||$allowed[$action][0]!==$p->status||!in_array($p->status,['draft','in_review'],true)){throw new LogicException('Transición no permitida desde el estado actual.');}
                if(in_array($action,['approve','reject'],true)&&!$canApprove){throw new LogicException('La decisión final requiere permiso de aprobación.');}
                if(in_array($action,['reject','return','cancel'],true)){$this->reason($data);}
                if(in_array($action,['submit','approve'],true)){$warnings=$this->validateLines($p);}
                if($action==='approve'){
                    if(!hash_equals(hash('sha256',json_encode($warnings,JSON_THROW_ON_ERROR)),(string)($data['review_hash']??''))){throw new LogicException('Las advertencias comerciales cambiaron o no se revisaron. Recargue el detalle antes de aprobar.');}
                    if(($data['confirmed']??false)!==true){throw new InvalidArgumentException('Confirme expresamente las cantidades, hipótesis y advertencias.');}
                    foreach($p->items()->whereNull('removed_at')->get() as $line){$decision=$line->decision;$decision['approved']=$decision['requested'];$decision['approved_by']=$actor;$decision['approved_at']=now()->toIso8601String();$line->decision=$decision;$line->approved_packages=$this->summary($decision['requested']['packages']);$line->approved_base_units=$this->summary($decision['requested']['base_units']);$line->save();}
                    $p->approved_at=now();
                }
                $p->status=$allowed[$action][1];$p->reviewed_by=$actor;
            }
            $p->version++;$p->save();$this->event($p,$request,$hash,$actor,$action,$before,['proposal'=>$this->auditSnapshot($p,$generationId,$data),'reason'=>$data['reason']??null,'warnings'=>$warnings,'confirmed'=>$data['confirmed']??false,'transport_hash'=>$data['transport_hash']??null]);return $p->fresh();
        });
    }

    /**
     * Conserva la cabecera y el cambio atribuible al comando. En una construcción por generación,
     * cada línea ya consta en su propio evento y en su evidencia original.
     * @param PurchaseProposal $proposal Cabecera bloqueada antes o después de la operación.
     * @param int|null $generationId Construcción por bloques. Las operaciones ordinarias conservan la fotografía completa.
     * @param array $data Identidad del producto o línea afectada.
     * @return array Fotografía con el alcance explícito de sus líneas.
     */
    private function auditSnapshot(PurchaseProposal $proposal, ?int $generationId, array $data): array
    {
        if ($generationId === null) {
            return $this->detail($proposal->id);
        }
        $line = null;
        if (isset($data['item_id'])) {
            $line = $proposal->items()->find($data['item_id']);
        } elseif (isset($data['product_id'])) {
            $line = $proposal->items()->where('product_id',$data['product_id'])->whereNull('removed_at')->first();
        }
        return $proposal->toArray() + ['generation_id'=>$generationId,'items_scope'=>'affected_lines',
            'items'=>$line === null ? [] : [$line->toArray()]];
    }

    /** Agrega o ajusta una línea conservando evidencia original y fotografía de cada decisión. @param PurchaseProposal $p Cabecera bloqueada. @param string $action add o adjust. @param array $data Selección y cantidades explícitas. @param ReplenishmentPlanData|null $plan Cálculo verificado. @return array Advertencias de propuestas simultáneas. @throws InvalidArgumentException Cantidades, maestros o selección incompatibles. */
    private function line(PurchaseProposal $p,string $action,array $data,?ReplenishmentPlanData $plan): array
    {
        $line=$action==='adjust'?$p->items()->whereNull('removed_at')->findOrFail($data['item_id']):null;
        $product=$line?->product_id??$data['product_id'];$presentationId=(int)$data['presentation_id'];
        [$presentation,$master]=$this->presentation($p,$product,$presentationId,true);
        if(!$line&&$p->items()->whereNull('removed_at')->where('product_id',$product)->exists()){throw new InvalidArgumentException('El producto ya existe en esta propuesta; ajuste su línea.');}
        $overlaps=$this->overlaps($product,$p->id);
        if($overlaps&&($data['accept_overlap']??false)!==true){throw new InvalidArgumentException('El producto aparece en otras propuestas abiertas: '.json_encode($overlaps,JSON_UNESCAPED_UNICODE).'. Confirme expresamente la división de compra.');}
        $reason=$this->reason($data);$evidence=null;$sourceId=null;
        if($plan!==null){
            $r=$plan->report;
            if($line||$r['product_id']!==$product||$r['supplier_id']!==$p->supplier_id||$r['location_id']!==$p->location_id||($r['presentation']['supplier_presentation_id']??null)!==$presentationId){throw new InvalidArgumentException('El cálculo no corresponde a la propuesta y presentación seleccionadas.');}
            if(!in_array($r['status'],['recommended','simulation','not_required'],true)||$r['quantities']['suggested_packages']===null){throw new InvalidArgumentException('El cálculo no tiene cantidad de compra válida. Use una decisión manual explícita.');}
            $requested=$this->quantity($r['quantities']['suggested_packages'],'packages',$presentation);
            $requested['rounding_excess']=$r['quantities']['rounding_excess'];
            $evidence=['source'=>$r['status']==='simulation'?'simulation':'calculated','report'=>$r,'original'=>$requested,'warnings'=>$this->planWarnings($r)];
            $ids=$this->store->persist($plan);$sourceId=$ids['result_id'];
        }else{$requested=$this->quantity($data['quantity']??null,$data['quantity_unit']??'',$presentation);$need=$line?->evidence['report']['quantities']['required_base_units']??null;$requested['rounding_excess']=$need===null?null:(string)BigDecimal::max('0',BigDecimal::of($requested['base_units'])->minus($need));}
        if(!$line){
            $line=new PurchaseProposalItem(['purchase_proposal_id'=>$p->id,'product_id'=>$product,'supplier_presentation_id'=>$presentationId,'source_result_id'=>$sourceId,
                'suggested_base_units'=>$this->summary($requested['base_units']),'suggested_packages'=>$plan?$this->summary($requested['packages']):null,
                'evidence'=>($evidence??['source'=>'manual','original'=>null,'reason'=>$reason,'warnings'=>['Cantidad manual: decisión comercial sin pronóstico certificado.']])+['generation'=>$data['_generation_reference']??null]]);
        }
        $line->supplier_presentation_id=$presentationId;$line->adjustment_reason=$reason;
        $line->decision=['requested'=>$requested,'approved'=>null,'reason'=>$reason,'source'=>$action==='adjust'?'adjusted':($evidence['source']??'manual'),'product_name'=>$master->name,'base_unit'=>$master->stockUnit,'presentation'=>$presentation,'overlaps'=>$overlaps,'overlap_confirmed'=>$data['accept_overlap']??false];$cost=$line->evidence['report']['policy_generation']['cost']??null;
        if(($cost['quality']??null)==='verified'&&($cost['unit_cost']??null)!==null&&($cost['cost_unit']??null)===$master->stockUnit&&!empty($cost['currency'])){
            $line->estimated_unit_cost=$this->summary($cost['unit_cost']);$line->currency_code=$cost['currency'];$decision=$line->decision;$decision['cost']=$cost;$decision['cost']['package_cost']=(string)BigDecimal::of($cost['unit_cost'])->multipliedBy($presentation['base_units_per_package']);$decision['cost']['subtotal']=(string)BigDecimal::of($cost['unit_cost'])->multipliedBy($requested['base_units']);$line->decision=$decision;
        }
        $line->save();return $overlaps;
    }

    /** Valida cantidades humanas sin redondearlas y reutiliza el empaquetado existente. @param mixed $value Decimal exacto textual. @param string $unit packages o base. @param array $presentation Instantánea comercial. @return array Cantidades exactas. @throws InvalidArgumentException Si no cumple mínimo, múltiplo o precisión. */
    public function quantity(mixed $value,string $unit,array $presentation): array
    {
        if(!is_string($value)||!preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D',$value)||!in_array($unit,['packages','base'],true)){throw new InvalidArgumentException('Introduzca cantidad exacta y unidad packages o base.');}
        $q=BigDecimal::of($value);if(!$q->isPositive()){throw new InvalidArgumentException('La cantidad de compra debe ser positiva; no se agregan líneas cero.');}
        $factor=BigDecimal::of($presentation['base_units_per_package']);$base=$unit==='base'?$q:$q->multipliedBy($factor);$calculated=$this->calculator->packages((string)$base,$presentation);
        if(!BigDecimal::of($calculated['base_units'])->isEqualTo($base)){throw new InvalidArgumentException('La cantidad no respeta mínimo o múltiplo. Corríjala explícitamente; no se redondea una decisión humana.');}
        return $calculated;
    }

    /** Comprueba identidad ERP y relación propia; sólo nuevas selecciones exigen revisión actual. @param PurchaseProposal $p Propuesta. @param string $product Producto. @param int $id Presentación. @param bool $current Exige revisión vigente. @return array Instantánea y producto normalizado. @throws InvalidArgumentException Referencias inválidas. */
    private function presentation(PurchaseProposal $p,string $product,int $id,bool $current): array
    {
        if(!$this->adapters->resolve('suppliers')->find($p->supplier_id)){throw new InvalidArgumentException('El proveedor ya no está disponible.');}
        $master=$this->adapters->resolve('products')->find($product);if(!$master||!$master->stockUnit){throw new InvalidArgumentException('Producto o unidad base no verificables.');}
        $model=SupplierPresentation::query()->lockForUpdate()->findOrFail($id);$snapshot=$this->query->presentation($model);
        if($snapshot['product_id']!==$product||$snapshot['supplier_id']!==$p->supplier_id||!$snapshot['relation_active']||!$snapshot['active']||($current&&!$snapshot['current_revision'])){throw new InvalidArgumentException('Seleccione una presentación activa y vigente del producto y proveedor.');}
        return [$snapshot,$master];
    }

    /** Revalida todas las líneas para revisión/aprobación conservando revisiones históricas explícitas. @param PurchaseProposal $p Propuesta bloqueada. @return array Advertencias finales. @throws InvalidArgumentException Si hay datos inválidos o falta evidencia. */
    private function validateLines(PurchaseProposal $p): array
    {
        $items=$p->items()->whereNull('removed_at')->get();if($items->isEmpty()){throw new InvalidArgumentException('La propuesta necesita al menos una línea.');}$seen=[];$warnings=[];
        foreach($items as $line){
            if(isset($seen[$line->product_id])){throw new InvalidArgumentException('Hay productos duplicados.');}$seen[$line->product_id]=true;
            [$snapshot,$master]=$this->presentation($p,$line->product_id,$line->supplier_presentation_id,false);
            if(!$line->evidence||!$line->decision||$line->decision['base_unit']!==$master->stockUnit){throw new InvalidArgumentException('La evidencia o la unidad base cambió. Devuelva la propuesta para corregir.');}
            $quantity=$this->quantity($line->decision['requested']['packages'],'packages',$snapshot);
            if(!BigDecimal::of($quantity['base_units'])->isEqualTo($line->decision['requested']['base_units'])){throw new InvalidArgumentException('La equivalencia comercial no coincide.');}
            if(!$snapshot['current_revision']){$warnings[]='Producto '.$line->product_id.': revisión comercial histórica conservada; no se sustituyó automáticamente.';}
            $warnings=array_merge($warnings,$line->evidence['warnings']??[]);
            foreach($this->overlaps($line->product_id,$p->id) as $overlap){$warnings[]='Compra simultánea: '.json_encode($overlap,JSON_UNESCAPED_UNICODE);}
        }return array_values(array_unique($warnings));
    }

    /** Identifica propuestas abiertas sin convertirlas en pendiente certificado. @param string $product Identidad exacta. @param int $except Cabecera excluida. @return array Referencias comerciales. */
    public function overlaps(string $product,int $except=0): array
    {
        return PurchaseProposal::where('id','!=',$except)->whereIn('status',['draft','in_review','approved'])->whereHas('items',fn($q)=>$q->where('product_id',$product)->whereNull('removed_at'))->get(['id','supplier_id','status','location_id'])->toArray();
    }

    /** Conserva riesgo de agotamiento sin inferir fechas ni ventas perdidas. @param array $r Informe original. @return array Advertencias explícitas. */
    private function planWarnings(array $r): array
    {
        $warnings=$r['warnings'];if($r['status']==='simulation'){$warnings[]='Simulación basada en hipótesis; la aprobación humana no certifica su pronóstico.';}
        $projected=$r['quantities']['projected_stock_at_receipt'];if($projected!==null&&BigDecimal::of($projected)->isNegative()){$warnings[]='Según las hipótesis actuales, la existencia podría resultar insuficiente antes de recibir la mercancía. Déficit proyectado: '.BigDecimal::of($projected)->abs().' '.$r['base_unit'].'. No acredita ventas perdidas ni fecha de agotamiento.';}return $warnings;
    }

    /** Exige motivo comercial no vacío para toda alta, ajuste o decisión negativa. @param array $data Entrada. @return string Motivo. @throws InvalidArgumentException Si falta. */
    private function reason(array $data): string {$reason=$data['reason']??null;if(!is_string($reason)||trim($reason)===''||mb_strlen($reason)>2000){throw new InvalidArgumentException('Indique una justificación de hasta 2000 caracteres.');}return $reason;}
    /** Genera un resumen compatible con columnas heredadas; JSON conserva el valor exacto. @param string $value Cantidad exacta. @return string Resumen de seis decimales HALF_UP. */
    private function summary(string $value): string {return (string)BigDecimal::of($value)->toScale(6,RoundingMode::HALF_UP);}
    /** Valida identidad de operación y calcula huella estable del comando. @param string $action Acción. @param array $data Entrada. @param string $actor Actor real. @param string $request UUID. @return string SHA256. @throws InvalidArgumentException Identidad inválida. */
    private function hash(string $action,array $data,string $actor,string $request): string {if(trim($actor)===''||strlen($actor)>100||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$request)){throw new InvalidArgumentException('Actor o UUID de operación inválido.');}return hash('sha256',json_encode([$action,$data,$actor],JSON_THROW_ON_ERROR));}
    /** Recupera el resultado de un comando ya confirmado sin repetir escrituras. @param string $request UUID. @param string $hash Huella. @return PurchaseProposal|null Propuesta vigente. @throws LogicException Reutilización incompatible. */
    private function retry(string $request,string $hash): ?PurchaseProposal {$event=ProposalEvent::where('request_id',$request)->first();if(!$event){return null;}if(!hash_equals($event->request_hash,$hash)){throw new LogicException('UUID ya utilizado para otra operación.');}return PurchaseProposal::findOrFail($event->purchase_proposal_id);}
    /** Añade evento inmutable en la transacción de dominio. @param PurchaseProposal $p Cabecera. @param string $request UUID. @param string $hash Huella. @param string $actor Responsable. @param string $action Acción. @param array|null $before Estado anterior. @param array $after Estado posterior y motivos. @return void */
    private function event(PurchaseProposal $p,string $request,string $hash,string $actor,string $action,?array $before,array $after): void {ProposalEvent::create(['purchase_proposal_id'=>$p->id,'request_id'=>$request,'request_hash'=>$hash,'actor_id'=>$actor,'action'=>$action,'version'=>$p->version,'payload'=>['before'=>$before,'after'=>$after,'storage'=>'JSON conserva decimales exactos; columnas heredadas son resúmenes HALF_UP a seis decimales.']]);}
    /** Prepara advertencias visibles y su huella antes de solicitar aprobación. @param int $id Propuesta. @return array Advertencias, bloqueo comercial y huella de confirmación. */
    public function review(int $id): array {try{$warnings=$this->validateLines(PurchaseProposal::findOrFail($id));return ['warnings'=>$warnings,'error'=>null,'hash'=>hash('sha256',json_encode($warnings,JSON_THROW_ON_ERROR))];}catch(InvalidArgumentException $error){return ['warnings'=>[],'error'=>$error->getMessage(),'hash'=>null];}catch(\Throwable $error){return ['warnings'=>[],'error'=>'No se pudieron revalidar las fuentes; la aprobación permanece bloqueada.','hash'=>null];}}
    /** Lee cabecera y líneas, incluidas retiradas, sin consultar ni escribir ERP. @param int $id Propuesta. @return array Fotografía de estado, sin eventos recursivos. */
    public function detail(int $id): array {$p=PurchaseProposal::findOrFail($id);return $p->toArray()+['items'=>$p->items()->orderBy('id')->get()->toArray()];}
}
