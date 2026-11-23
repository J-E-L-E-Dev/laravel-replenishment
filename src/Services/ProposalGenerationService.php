<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\{ProposalGeneration,ProposalGenerationItem,ProposalGenerationEvent,Policy,ProductSupplier,SupplierPresentation,PurchaseProposal};
use Asisnet\Replenishment\Models\GenerationAttempt;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

/** Procesamiento durable de selección explícita; una transacción corta procesa un producto cada vez. */
final class ProposalGenerationService
{
    /** Recibe persistencia aislada, planificación y reglas de decisiones existentes. @param PersistenceConnection $connection Destino propio. @param GenerationWork $work Procesamiento individual recuperable. @param PurchaseProposalService $proposals Cantidades y flujo aprobable. @param ReplenishmentQuery $query Revisión comercial vigente. */
    public function __construct(private PersistenceConnection $connection, private GenerationWork $work, private PurchaseProposalService $proposals, private ReplenishmentQuery $query) {}

    /**
     * Congela identidades del conjunto previamente filtrado por el consumidor; no calcula ni consulta históricos.
     * @param string $supplier Proveedor canónico.
     * @param Policy $policy Política elegida.
     * @param string $location Ubicación autorizada por el transporte.
     * @param iterable $relations Identidades explícitas o pares [relación, revisión] resueltos en servidor para toda la búsqueda.
     * @param array $selection Filtros y modalidad de selección auditables.
     * @param array $options Hipótesis y parámetros elegidos.
     * @param string $actor Actor autenticado.
     * @param string $request UUID del inicio; reintentos conservan el conjunto congelado original.
     * @return ProposalGeneration Ejecución durable con selección completa.
     * @throws InvalidArgumentException Si la selección, política o ámbito son inválidos.
     * @throws LogicException Si el UUID ya corresponde a otra solicitud.
     */
    public function start(string $supplier, Policy $policy, string $location, iterable $relations, array $selection, array $options, string $actor, string $request): ProposalGeneration
    {
        $hash=$this->hash([$supplier,$policy->id,$location,$selection,$options],$actor,$request);
        if($prior=ProposalGeneration::where('request_id',$request)->first()){if($prior->request_hash!==$hash){throw new GenerationConflict('generation_command','UUID utilizado con otra selección.');}return $prior;}
        if(isset($options['budget_amount'])&&(!is_string($options['budget_amount'])||!preg_match('/^\d{1,18}(?:[.]\d{1,12})?$/D',$options['budget_amount'])||!is_string($options['budget_currency']??null)||!preg_match('/^[A-Z]{3}$/D',$options['budget_currency']))){throw new InvalidArgumentException('El presupuesto requiere decimal exacto no negativo y moneda explícita.');}
        if(!$policy->active||$supplier===''||$location===''){throw new InvalidArgumentException('Seleccione proveedor, destino y política activa.');}
        $mode = $options['statistics_mode'] ?? 'reuse_or_refresh_missing';
        if (!in_array($mode,GenerationStatistics::MODES,true)) {
            throw new InvalidArgumentException('Seleccione una modalidad de preparación estadística válida.');
        }
        // Resolver los filtros puede consultar el catálogo externo, siempre antes de abrir la transacción propia.
        $ids = [];
        $reviewed = $selection['presentation_ids'] ?? [];
        foreach ($relations as $entry) {
            if (is_array($entry)) {
                if (!array_is_list($entry) || count($entry) !== 2 || !is_int($entry[0]) || !is_int($entry[1])) {
                    throw new InvalidArgumentException('El conjunto filtrado requiere identidades enteras de relación y revisión.');
                }
                [$id, $revision] = $entry;
                $reviewed[$id] = $revision;
            } else {
                $id = $entry;
            }
            if (!is_int($id) || isset($ids[$id]) || count($ids) >= 10000) {
                throw new InvalidArgumentException('Seleccione hasta diez mil identidades enteras distintas.');
            }
            $ids[$id] = $id;
        }
        $relations = array_values($ids);
        unset($ids);
        $count = count($relations);
        if (!$count || isset($selection['expected_count']) && $count !== $selection['expected_count']) {
            throw new GenerationConflict('configuration_changed','La selección está vacía o su total cambió. Consulte nuevamente los filtros.');
        }
        if ($count !== 1 && (isset($options['manual_daily']) || isset($options['conservative_daily']))) {
            throw new InvalidArgumentException('Seleccione un solo producto para autorizar una tasa manual individual.');
        }
        return $this->connection->transaction(function()use($reviewed,$mode,$supplier,$policy,$location,$relations,$selection,$options,$actor,$request,$hash):ProposalGeneration{
            $policy=Policy::query()->lockForUpdate()->findOrFail($policy->id);if(!$policy->active){throw new GenerationConflict('configuration_changed','La política fue desactivada.');}
            if ($prior=ProposalGeneration::where('request_id',$request)->first()) {
                if ($prior->request_hash!==$hash) {throw new GenerationConflict('generation_command','UUID utilizado con otra selección.');}
                return $prior;
            }
            $this->verifyPurchaseSelection($supplier, $relations, $reviewed);
            $frozen = $policy->toArray();
            $settings = PolicyDemandSettings::normalize($policy->demand_settings ?? []);
            if (isset($options['conservative_daily'])) {
                if ($policy->demand_settings === null) {
                    $settings['conservative'] = 'manual';
                } elseif ($settings['conservative'] !== 'manual') {
                    throw new InvalidArgumentException('La política no autoriza una tasa conservadora manual.');
                }
            }
            $frozen['demand_settings'] = $settings;
            $generation=ProposalGeneration::create(['request_id'=>$request,'request_hash'=>$hash,'supplier_id'=>$supplier,'location_id'=>$location,'actor_id'=>$actor,'status'=>'processing','version'=>1,'selection_manifest'=>$relations,'snapshot'=>['statistics_mode'=>$mode,'timezone'=>config('app.timezone','UTC'),'algorithm_version'=>PolicyDrivenPlanningService::VERSION,'policy'=>$frozen,'as_of'=>CarbonImmutable::now(config('app.timezone','UTC'))->startOfDay()->format(DATE_ATOM),'selection'=>$selection,'options'=>$options]]);
            $count=count($relations);
            $this->freezeBlock($generation);
            $this->event($generation,$request,$hash,$actor,'start',['selected'=>$count]);return $generation;
        });
    }

    /**
     * Revalida dentro de la transacción de inicio, antes de crear la ejecución. Los reintentos
     * de un inicio ya confirmado recuperan directamente su generación original.
     * @param string $supplier Proveedor verificado.
     * @param array $ids Relaciones que se van a congelar.
     * @param array $reviewed Identidades de revisión observadas en la selección, cuando se suministran.
     * @return void
     * @throws LogicException Si un producto pierde su presentación única o cambia de revisión.
     */
    private function verifyPurchaseSelection(string $supplier, array $ids, array $reviewed): void
    {
        sort($ids, SORT_NUMERIC);
        foreach (array_chunk($ids, 200) as $block) {
            $relations = ProductSupplier::whereIn('id', $block)->where('supplier_id', $supplier)
                ->orderBy('id')->lockForUpdate()->with([
                    'currentPresentations'=>fn ($query) => $query->lockForUpdate(),
                    'currentPresentations.purchaseUnit'=>fn ($query) => $query->lockForUpdate(),
                ])->get()->keyBy('id');
            foreach ($block as $id) {
                $relation = $relations->get($id);
                $revision = $relation?->purchasePresentationId();
                if ($revision === null || (isset($reviewed[$id]) && $revision !== (int) $reviewed[$id])) {
                    $product = $relation?->product_id ?? "relación #{$id}";
                    throw new GenerationConflict('configuration_changed',"El producto {$product} perdió su elegibilidad o cambió de presentación. Consulte y confirme otra vez la selección.");
                }
            }
        }
    }

    /**
     * Ejecuta un comando idempotente bajo bloqueo y versión; el actor nunca procede del cuerpo HTTP.
     * @param int $id Ejecución.
     * @param int $version Versión esperada.
     * @param string $action process, decide, begin_save, save_next o cancel_save.
     * @param array $data Decisión explícita; el cálculo siempre proviene del servidor.
     * @param string $actor Actor autenticado.
     * @param string $request UUID único por comando.
     * @return ProposalGeneration Estado vigente.
     * @throws LogicException Si hay conflicto, cambio de fecha o ejecución finalizada.
     * @throws InvalidArgumentException Si la decisión incumple presentación o falta motivo.
     */
    public function execute(int $id,int $version,string $action,array $data,string $actor,string $request): ProposalGeneration
    {
        $hash=$this->hash([$id,$version,$action,$data],$actor,$request);
        if (in_array($action,['process','retry','recalculate'],true)) {
            return $this->work->execute($id,$version,$action,$data,$actor,$request,$hash);
        }
        $prepared = $this->prepareItem($id, $version, $action, $request, $actor);
        return $this->connection->transaction(function()use($id,$version,$action,$data,$actor,$request,$hash,$prepared):ProposalGeneration{
            $g=ProposalGeneration::query()->lockForUpdate()->findOrFail($id);
            if($event=ProposalGenerationEvent::where('request_id',$request)->first()){if($event->request_hash!==$hash){throw new GenerationConflict('generation_command','UUID usado para otra operación.');}return $g;}
            if($g->version!==$version){throw new GenerationConflict('version_mismatch','La ejecución cambió; recargue su versión.');}
            if(!in_array($g->status,['initializing','processing','ready','saving'],true) || ($g->status==='initializing' && $action!=='initialize')){throw new GenerationConflict('generation_closed','La ejecución no admite esta operación en su estado actual.');}
            $held = ProposalGenerationItem::where('generation_id',$id)->whereNotNull('lease_token')->lockForUpdate()->first();
            if ($held !== null) {
                if (CarbonImmutable::parse($held->getRawOriginal('lease_until'),'UTC') > CarbonImmutable::now('UTC')) {
                    throw new GenerationConflict('lease_active','El producto '.$held->product_id.' tiene una reserva activa. Espere a que termine el intento.');
                }
                if ($action !== 'cancel_save') {
                    throw new GenerationConflict('lease_expired','La reserva de '.$held->product_id.' venció. Recupere el intento interrumpido.');
                }
                $attempt = GenerationAttempt::where('token',$held->lease_token)->firstOrFail();
                $attempt->evidence = $attempt->evidence + ['interrupted_stage'=>$attempt->stage,
                    'lease_until_utc'=>$held->getRawOriginal('lease_until')];
                $attempt->stage = 'expired';
                $attempt->save();
                $held->technical_state = 'intervention_required';
                $held->lease_token = $held->lease_until = null;
                $held->save();
            }
            if (in_array($action,['begin_save','save_next','bulk_decide'],true)) {
                $this->work->day($g);
            }
            $payload=[];
            if($g->status==='saving'&&!in_array($action,['save_next','cancel_save','recalculate','retry','decide'],true)){throw new GenerationConflict('generation_command','La ejecución está publicando líneas; sólo admite reanudar el guardado.');}
            if ($action === 'bulk_decide') {
                if (($data['confirmed'] ?? false) !== true || !is_string($data['reason'] ?? null) || trim($data['reason']) === '') {
                    throw new InvalidArgumentException('Confirme el bloque y registre un motivo comercial.');
                }
                $preview = $this->bulkPreview($g,$data['kind'] ?? '',$data['result_filter'] ?? 'all');
                if (!$preview['count'] || (int)($data['expected_count'] ?? -1) !== $preview['count']) {
                    throw new GenerationConflict('generation_command','Cambió el conjunto elegible. Consulte de nuevo la vista previa.');
                }
                foreach ($preview['ids'] as $itemId) {
                    $g = $this->execute($g->id,$g->version,'decide',['item_id'=>$itemId,'reason'=>$data['reason'],
                        'excluded'=>$preview['kind']==='exclude_zero'],$actor,$this->uuid($request.':'.$itemId));
                }
                $payload=$preview + ['reason'=>$data['reason']];
            }elseif ($action==='initialize') {
                if ($g->status!=='initializing') {throw new GenerationConflict('generation_command','La selección ya fue preparada.');}
                $this->freezeBlock($g);
            }elseif($action==='cancel_save'){
                if($g->status!=='saving'||($data['confirmed']??false)!==true||!is_string($data['reason']??null)||trim($data['reason'])===''){throw new InvalidArgumentException('Confirme la cancelación con un motivo explícito.');}
                $p=PurchaseProposal::findOrFail($g->proposal_id);$this->proposals->execute($p->id,$p->version,'cancel',['reason'=>$data['reason']],$actor,$this->uuid($g->request_id.':cancel'),false,null,$g->id);$g->status='cancelled';$payload=['reason'=>$data['reason'],'proposal_id'=>$p->id];
            }elseif($action==='begin_save'){
                if($g->status!=='ready'||($data['confirmed']??false)!==true){throw new InvalidArgumentException('Revise y confirme una ejecución terminada.');}
                if(!ProposalGenerationItem::where('generation_id',$id)->whereNotNull('decision')->exists()||ProposalGenerationItem::where('generation_id',$id)->whereNull('decision')->exists()){throw new InvalidArgumentException('Decida incluir o excluir cada producto antes de guardar.');}
                $included=0;foreach(ProposalGenerationItem::where('generation_id',$id)->select('id','decision')->lazyById(100) as $row){if(!$row->decision['excluded']){$included++;}}
                if($included===0){throw new InvalidArgumentException('Incluya al menos un producto con cantidad positiva.');}
                $proposal=$this->proposals->create(['supplier_id'=>$g->supplier_id,'location_id'=>$g->location_id,'notes'=>'Generación por política #'.$g->id.'. Conservar su evidencia y exclusiones.'],$actor,$this->uuid($g->request_id.':proposal'));
                $g->proposal_id=$proposal->id;$g->status='saving';$payload=['proposal_id'=>$proposal->id,'included'=>$included];
            }elseif($action==='save_next'){
                if($g->status!=='saving'){throw new GenerationConflict('generation_command','Confirme primero el guardado de la ejecución.');}
                $row=ProposalGenerationItem::where('generation_id',$id)->where('saved',false)->orderBy('id')->lockForUpdate()->first();
                if($row){if (($prepared['id'] ?? null) !== $row->id) {throw new GenerationConflict('generation_command','Cambió la línea pendiente de publicación.');}
                    try {$this->saveItem($g,$row,$actor,$prepared['result']);}
                    catch(GenerationConflict $e){throw new GenerationConflict($e->reason,'Producto '.$row->product_id.'. '.$e->getMessage());}$row->saved=true;$row->save();$payload=['item_id'=>$row->id,'excluded'=>$row->decision['excluded']];}
                if(!ProposalGenerationItem::where('generation_id',$id)->where('saved',false)->exists()){
                    if(!PurchaseProposal::findOrFail($g->proposal_id)->items()->exists()){throw new GenerationConflict('generation_command','La propuesta quedaría vacía. Cancele formalmente la construcción.');}
                    $g->status='saved';
                }
            }elseif($action==='decide'){
                $row=ProposalGenerationItem::where('generation_id',$id)->lockForUpdate()->findOrFail($data['item_id']??0);if($row->saved || $g->status==='saving' && ($row->decision!==null || $row->previous_choice===null)){throw new GenerationConflict('generation_command','La línea ya fue confirmada. Actualice y recalcule la línea pendiente antes de revisarla.');}if($row->status==='pending'){throw new GenerationConflict('generation_command','Procese el producto antes de decidir.');}
                $reason=$data['reason']??null;if(!is_string($reason)||trim($reason)===''||mb_strlen($reason)>2000){throw new InvalidArgumentException('Indique un motivo de hasta 2000 caracteres.');}
                $before=$row->decision;$decision=['excluded'=>($data['excluded']??false)===true,'reason'=>$reason,'actor_id'=>$actor,'decided_at'=>CarbonImmutable::now('UTC')->format(DATE_ATOM)];
                if(!$decision['excluded']){
                    $presentation=$this->query->presentation(SupplierPresentation::findOrFail($data['presentation_id']??$row->result['presentation']['supplier_presentation_id']??0));
                    if($presentation['product_id']!==$row->product_id||$presentation['supplier_id']!==$g->supplier_id||!$presentation['active']||!$presentation['relation_active']||!$presentation['current_revision']){throw new InvalidArgumentException('Seleccione una presentación activa y vigente del producto.');}
                    $quantity=$data['quantity']??$row->result['packages']['packages']??null;$decision['quantity']=$this->proposals->quantity($quantity,'packages',$presentation);$decision['presentation']=$presentation;
                    $decision['manual']=($row->result['packages']['packages']??null)===null||!BigDecimal::of($quantity)->isEqualTo($row->result['packages']['packages'])||$presentation!==($row->result['presentation']??null);
                    $cost=$row->result['cost']??null;$decision['cost']=null;
                    if(($cost['unit_cost']??null)!==null&&($cost['cost_unit']??null)===($row->result['base_unit']??null)&&($cost['currency']??null)!==null){$decision['cost']=$cost;$decision['cost']['package_cost']=(string)BigDecimal::of($cost['unit_cost'])->multipliedBy($presentation['base_units_per_package']);$decision['cost']['subtotal']=(string)BigDecimal::of($cost['unit_cost'])->multipliedBy($decision['quantity']['base_units']);}
                    $decision['accept_overlap']=($data['accept_overlap']??false)===true;
                }
                $row->decision=$decision;$row->save();$payload=['item_id'=>$row->id,'before'=>$before,'after'=>$decision];
            }else{throw new InvalidArgumentException('Comando de generación no reconocido.');}
            $g->version++;$g->save();$this->event($g,$request,$hash,$actor,$action,$payload);return $g;
        });
    }

    /** Lee estadísticas antes de tomar bloqueos. El comando comprueba nuevamente versión, identidad e idempotencia al escribir.
     * @param int $id Generación. @param int $version Versión observada. @param string $action Operación solicitada.
     * @param string $request UUID del comando. @param string $actor Usuario autenticado.
     * @return array Identidad y cálculo privado de un producto. @throws LogicException Si cambió el día operativo.
     * @throws \Throwable Si la revalidación para publicar no puede completarse. */
    private function prepareItem(int $id, int $version, string $action, string $request, string $actor): array
    {
        if ($action !== 'save_next' || ProposalGenerationEvent::where('request_id',$request)->exists()) {
            return [];
        }
        $generation = ProposalGeneration::findOrFail($id);
        if ($generation->version !== $version || $generation->status !== 'saving') {
            return [];
        }
        $row = ProposalGenerationItem::where('generation_id',$id)->where('saved',false)->orderBy('id')->first();
        if ($row === null) {
            return [];
        }
        if ($row->decision === null) {
            throw new GenerationConflict('generation_command','Revise y confirme la decisión del producto '.$row->product_id.'.');
        }
        if ($row->decision['excluded']) {
            return ['id'=>$row->id,'result'=>null];
        }
        $this->work->day($generation);
        $frozen = $row->result;
        $prepared = $frozen['statistics_preparation'] ?? null;
        if ($prepared === null && isset($frozen['demand_evidence']['window']['run_id'])) {
            $window = $frozen['demand_evidence']['window'];
            $prepared = ['origin'=>'reused','sales_run_id'=>$window['run_id'],'profile_hash'=>$window['profile_hash'],'unit'=>$window['unit']];
        }
        try {
            $result = $this->work->calculate($generation,$row,$frozen['actor_id'] ?? $actor,$prepared);
        } catch (\Throwable $error) {
            throw new GenerationConflict('generation_command','Producto '.$row->product_id.'. No se pudo revalidar su evidencia. Actualice y recalcule esta línea.');
        }
        return ['id'=>$row->id,'result'=>$result];
    }

    /** Consulta un bloque elegible en servidor sin modificar decisiones. El alcance siempre es la generación congelada.
     * @param ProposalGeneration $g Generación lista. @param string $kind Aceptar sugerencias o excluir necesidad cero.
     * @param string $filter Filtro de resultados visible. @return array Total e identidades de hasta 25 líneas.
     * @throws InvalidArgumentException Ante filtros o estados inválidos. */
    public function bulkPreview(ProposalGeneration $g,string $kind,string $filter): array
    {
        if ($g->status !== 'ready' || !in_array($kind,['accept','exclude_zero'],true)
            || !in_array($filter,['all','purchase','no_purchase','missing','failed','review','insufficient'],true)) {
            throw new InvalidArgumentException('Seleccione un bloque de decisiones válido de una generación lista.');
        }
        $rows=ProposalGenerationItem::where('generation_id',$g->id)->where('bulk_candidate',$kind)
            ->where('technical_state','processed')->where('saved',false)->whereNull('decision')->whereNull('previous_choice');
        if ($filter !== 'all' && $filter !== ($kind === 'accept' ? 'purchase' : 'no_purchase')) {
            $rows->whereRaw('1 = 0');
        }
        return ['kind'=>$kind,'result_filter'=>$filter,'count'=>(clone $rows)->count(),'ids'=>$rows->orderBy('id')->limit(25)->pluck('id')->all(),
            'version'=>$g->version,'generation_id'=>$g->id];
    }

    /** Crea otra evaluación del conjunto congelado, con la fecha vigente y decisiones previas para revisión.
     * @param int $id Generación de origen. @param int $version Versión revisada. @param string $scope all, missing o failed.
     * @param string $actor Usuario autenticado. @param string $request UUID. @return ProposalGeneration Nueva evaluación idempotente.
     * @throws LogicException Si cambia la selección o no hay productos en el ámbito. */
    public function derive(int $id,int $version,string $scope,string $actor,string $request): ProposalGeneration
    {
        if (!in_array($scope,['all','missing','failed'],true)) {
            throw new InvalidArgumentException('Ámbito de derivación inválido.');
        }
        $hash = $this->hash(['derive',$id,$version,$scope],$actor,$request);
        return $this->connection->transaction(function () use ($id,$version,$scope,$actor,$request,$hash) {
            $parent = ProposalGeneration::query()->lockForUpdate()->findOrFail($id);
            if ($prior = ProposalGeneration::where('request_id',$request)->first()) {
                if ($prior->request_hash !== $hash) {
                    throw new GenerationConflict('generation_command','UUID utilizado por otra derivación.');
                }
                return $prior;
            }
            if ($parent->version !== $version || $parent->status === 'initializing') {
                throw new GenerationConflict('version_mismatch','Recargue la versión de origen antes de derivar la selección.');
            }
            $rows = ProposalGenerationItem::where('generation_id',$id);
            if ($scope === 'missing') {
                $rows->where('diagnosis','refresh_required');
            } elseif ($scope === 'failed') {
                $rows->where('technical_state','recoverable_error');
            }
            $manifest = $rows->orderBy('id')->pluck('relation_id')->map(fn ($id)=>(int)$id)->all();
            $count = count($manifest);
            if (!$count) {
                throw new GenerationConflict('generation_command','El filtro no contiene productos.');
            }
            $snapshot = $parent->snapshot;
            if ($count > 1 && (isset($snapshot['options']['manual_daily']) || isset($snapshot['options']['conservative_daily']))) {
                throw new GenerationConflict('generation_command','Las tasas manuales requieren una selección individual.');
            }
            $snapshot['timezone'] = config('app.timezone','UTC');
            $snapshot['as_of'] = CarbonImmutable::now($snapshot['timezone'])->startOfDay()->format(DATE_ATOM);
            $snapshot['statistics_mode'] = $snapshot['options']['statistics_mode'] = 'force_refresh';
            $snapshot['algorithm_version'] = PolicyDrivenPlanningService::VERSION;
            $snapshot['parent_generation_id'] = $parent->id;
            $snapshot['selection'] = ['derived_from'=>$parent->id,'parent_version'=>$version,'scope'=>$scope,'expected_count'=>$count];
            $child = ProposalGeneration::create(['request_id'=>$request,'request_hash'=>$hash,'supplier_id'=>$parent->supplier_id,
                'location_id'=>$parent->location_id,'actor_id'=>$actor,'status'=>'processing','version'=>1,'snapshot'=>$snapshot,'selection_manifest'=>$manifest]);
            $this->freezeBlock($child);
            $this->event($child,$request,$hash,$actor,'derive',$snapshot['selection']);
            return $child;
        });
    }

    /** Crea hasta cien líneas de la selección ya congelada. Filas, cursor y estado se confirman juntos.
     * @param ProposalGeneration $g Cabecera bloqueada en la transacción del comando. @return void
     * @throws LogicException Si cambia el origen de una derivación antes de terminar su copia. */
    private function freezeBlock(ProposalGeneration $g): void
    {
        $manifest = $g->selection_manifest;
        $block = array_slice($manifest,(int)$g->freeze_cursor,100);
        $products = ProductSupplier::whereIn('id',$block)->where('supplier_id',$g->supplier_id)->pluck('product_id','id');
        $sources = collect();
        if ($parent = $g->snapshot['parent_generation_id'] ?? null) {
            $source = ProposalGeneration::query()->lockForUpdate()->findOrFail($parent);
            if ($source->version !== $g->snapshot['selection']['parent_version']) {
                throw new GenerationConflict('version_mismatch','Cambió la generación de origen. Cree una derivación de su versión actual.');
            }
            $sources = ProposalGenerationItem::where('generation_id',$parent)->whereIn('relation_id',$block)->get()->keyBy('relation_id');
        }
        foreach ($block as $id) {
            if (!isset($products[$id])) {
                throw new GenerationConflict('generation_command','Una relación congelada ya no pertenece al proveedor.');
            }
            $item = new ProposalGenerationItem(['generation_id'=>$g->id,'relation_id'=>$id,'product_id'=>$products[$id],'status'=>'pending']);
            if ($origin = $sources->get($id)) {
                $item->previous_choice = GenerationWork::comparison($origin->result ?? [],$origin->decision) + ['parent_item_id'=>$origin->id];
            }
            $item->save();
        }
        $g->freeze_cursor = (int)$g->freeze_cursor + count($block);
        $g->status = $g->freeze_cursor === count($manifest) ? 'processing' : 'initializing';
        $g->save();
    }

    /** Resume conteos completos, sin truncar resultados al tamaño de página. @param ProposalGeneration $generation Ejecución. @param bool $compact Omite la lista de identidades en consultas de progreso. @return array Conteos y estado. */
    public function summary(ProposalGeneration $generation,bool $compact=false): array
    {
        $rows = ProposalGenerationItem::where('generation_id',$generation->id);
        $groups = (clone $rows)->select('status','technical_state','statistics_origin','requires_review','diagnosis')
            ->selectRaw('COUNT(*) AS total')->groupBy('status','technical_state','statistics_origin','requires_review','diagnosis')->get();
        $counts = $origins = $diagnoses = [];
        $active = $pending = $review = 0;
        foreach ($groups as $group) {
            $n = (int)$group->total;
            $counts[$group->status] = ($counts[$group->status] ?? 0) + $n;
            $origins[$group->statistics_origin] = ($origins[$group->statistics_origin] ?? 0) + $n;
            $diagnoses[$group->diagnosis] = ($diagnoses[$group->diagnosis] ?? 0) + $n;
            if (in_array($group->technical_state,['preparing','calculating'],true)) {
                $active += $n;
            } elseif ($group->status === 'pending') {
                $pending += $n;
            }
            $review += $group->requires_review ? $n : 0;
        }
        $current = (clone $rows)->whereNotNull('lease_token')->first(['id','product_id','technical_state','lease_until','lease_token']);
        if ($current) {
            $attempt=GenerationAttempt::where('token',$current->lease_token)->first(['action']);
            $current->setAttribute('action',$attempt?->action);
            $current->setAttribute('lease_until_utc',CarbonImmutable::parse($current->getRawOriginal('lease_until'),'UTC')->toIso8601String());
            $current->setAttribute('expired',CarbonImmutable::parse($current->getRawOriginal('lease_until'),'UTC')<=CarbonImmutable::now('UTC'));
        }
        $selected = $generation->status === 'initializing' ? count($generation->selection_manifest) : array_sum($counts);
        $pending += $selected - array_sum($counts);
        $done = $selected - $active - $pending;
        $last = (clone $rows)->max('updated_at');
        $zone = config('app.timezone','UTC');
        $lastActivity = max((string)$generation->getRawOriginal('updated_at'),(string)$last);
        $day = (new \DateTimeImmutable($generation->snapshot['as_of']))->setTimezone(new \DateTimeZone($zone));
        $header = $generation->toArray();
        if ($compact) {
            unset($header['snapshot']['selection']['relation_ids']);
        }
        return $header + ['counts'=>$counts,'selected'=>$selected,'processed'=>$done,'pending'=>$pending,'processing'=>$active,
            'percent'=>$selected ? intdiv(100*$done,$selected) : 0,'current'=>$current,'last_activity'=>CarbonImmutable::parse($lastActivity,date_default_timezone_get())->utc()->format(DATE_ATOM),
            'new_day'=>$day != CarbonImmutable::now($zone)->startOfDay() || ($generation->snapshot['timezone'] ?? $zone) !== $zone,
            'reused'=>$origins['reused'] ?? 0,'refreshed'=>$origins['refreshed'] ?? 0,'review_required'=>$review,
            'refresh_required'=>$diagnoses['refresh_required'] ?? 0,
            'calculated'=>array_sum(array_intersect_key($counts,array_flip(['purchase_needed','no_purchase_needed','extended_coverage_candidate','declining_demand_review']))),
            'no_purchase'=>($counts['no_purchase_needed'] ?? 0)+($counts['extended_coverage_candidate'] ?? 0),
            'extended_coverage'=>$counts['extended_coverage_candidate'] ?? 0,'insufficient'=>$counts['insufficient_data'] ?? 0,'errors'=>$counts['error'] ?? 0];
    }

    /** Valida identidad y huella de intención, sin incluir resultados calculados por el cliente. @param array $data Intención. @param string $actor Actor. @param string $request UUID. @return string Huella SHA256. @throws InvalidArgumentException Si falta identidad válida. */
    private function hash(array $data,string $actor,string $request): string
    {
        if(trim($actor)===''||strlen($actor)>100||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$request)){throw new InvalidArgumentException('Actor o UUID inválido.');}return hash('sha256',json_encode([$data,$actor],JSON_THROW_ON_ERROR));
    }
    /** Añade evento inmutable en la transacción del comando. @param ProposalGeneration $g Ejecución. @param string $request UUID. @param string $hash Huella. @param string $actor Actor. @param string $action Acción. @param array $payload Evidencia. @return void */
    private function event(ProposalGeneration $g,string $request,string $hash,string $actor,string $action,array $payload): void
    {
        ProposalGenerationEvent::create(['generation_id'=>$g->id,'request_id'=>$request,'request_hash'=>$hash,'actor_id'=>$actor,'action'=>$action,'version'=>$g->version,'payload'=>$payload]);
    }
    /**
     * Añade una línea o conserva su exclusión, reutilizando validaciones y auditoría del flujo existente.
     * @param ProposalGeneration $generation Ejecución bloqueada.
     * @param ProposalGenerationItem $row Decisión explícita y resultado original.
     * @param string $actor Actor que confirma el bloque.
     * @return void
     * @param array|null $fresh Evidencia revalidada antes de adquirir el bloqueo de escritura.
     * @throws \Throwable Si cambia presentación o fuente; el bloque se revierte y permite reanudación.
     */
    private function saveItem(ProposalGeneration $generation,ProposalGenerationItem $row,string $actor,?array $fresh): void
    {
        $decision=$row->decision;if($decision['excluded']){return;}$p=PurchaseProposal::findOrFail($generation->proposal_id);$result=$row->result;
        $data=['product_id'=>$row->product_id,'presentation_id'=>$decision['presentation']['supplier_presentation_id'],'quantity'=>$decision['quantity']['packages'],'quantity_unit'=>'packages','reason'=>$decision['reason'],'accept_overlap'=>$decision['accept_overlap']];
        $hasCalculation=($result['packages']['packages']??null)!==null&&BigDecimal::of($result['packages']['packages'])->isPositive();
        $plan=null;
        if($fresh !== null){
            if (isset($result['demand_evidence']) && ($fresh['demand_evidence'] ?? null) != $result['demand_evidence']) {
                throw new GenerationConflict('generation_command','Cambió la ejecución comercial o su estrategia. Genere nuevamente el producto.');
            }
            foreach(['balance','packages','presentation','incoming','base_unit'] as $field){if(($fresh[$field]??null)!==($result[$field]??null)){throw new GenerationConflict('generation_command','La evidencia cambió desde la vista previa. Actualice y recalcule este producto.');}}
            foreach(['onHand','fixedReserved','manualReserved','quotationReserved','unclassifiedReserved','totalCommitted','available'] as $field){if(($fresh['availability'][$field]??null)!==($result['availability'][$field]??null)){throw new GenerationConflict('generation_command','Cambió la evidencia de inventario; genere nuevamente el producto.');}}
            foreach(['lead_time_days','source','measure'] as $field){if(($fresh['lead_time'][$field]??null)!==($result['lead_time'][$field]??null)){throw new GenerationConflict('generation_command','Cambió el plazo comercial; genere nuevamente el producto.');}}
            foreach(['unit_cost','currency','cost_unit'] as $field){if(($fresh['cost'][$field]??null)!==($result['cost'][$field]??null)){throw new GenerationConflict('generation_command','Cambió el costo; revise nuevamente el presupuesto y genere el producto.');}}
        }
        if($hasCalculation){
            $plan=$this->plan($result);$data['presentation_id']=$result['presentation']['supplier_presentation_id'];}
        $p=$this->proposals->execute($p->id,$p->version,'add',$data,$actor,$this->uuid($generation->request_id.':add:'.$row->id),false,$plan,$generation->id);
        if($hasCalculation&&$decision['manual']){$item=$p->items()->where('product_id',$row->product_id)->firstOrFail();$data['item_id']=$item->id;$data['presentation_id']=$decision['presentation']['supplier_presentation_id'];$p=$this->proposals->execute($p->id,$p->version,'adjust',$data,$actor,$this->uuid($generation->request_id.':adjust:'.$row->id),false,null,$generation->id);}
    }

    /** Adapta el diagnóstico al formato histórico existente, conservando evidencia completa por línea. @param array $r Cálculo original del servidor. @return \Asisnet\Replenishment\DTO\ReplenishmentPlanData Fotografía publicable. */
    private function plan(array $r): \Asisnet\Replenishment\DTO\ReplenishmentPlanData
    {
        $asOf=new \DateTimeImmutable($r['as_of']);$days=$r['protection_days']-$r['policy']['target_coverage_days'];$receipt=$asOf->modify('+'.$days.' days');$end=$receipt->modify('+'.$r['policy']['target_coverage_days'].' days');
        $q=['available_stock'=>$r['availability']['available']??null,'inventory_position'=>null,'horizon_demand'=>$r['demand_after_receipt']['quantity'],'consumption_before_receipt'=>$r['demand_before_receipt']['quantity'],'projected_stock_at_receipt'=>$r['balance']['projected'],'target_stock'=>$r['balance']['target'],'safety_stock'=>$r['safety_units'],'required_base_units'=>$r['balance']['required'],'suggested_packages'=>$r['packages']['packages'],'suggested_base_units'=>$r['packages']['base_units'],'rounding_excess'=>$r['packages']['rounding_excess'],'minimum_stock'=>$r['safety_units'],'maximum_stock'=>$r['balance']['target'],'reorder_point'=>null,'projected_coverage_days'=>null];
        return new \Asisnet\Replenishment\DTO\ReplenishmentPlanData(['algorithm_version'=>$r['algorithm_version'],'product_id'=>$r['product_id'],'supplier_id'=>$r['supplier_id'],'location_id'=>$r['location_id'],'base_unit'=>$r['base_unit'],'status'=>'simulation','horizon_start'=>$asOf->format('Y-m-d'),'horizon_end'=>$end->format('Y-m-d'),'presentation'=>$r['presentation'],'quantities'=>$q,'incoming'=>$r['incoming'],'dates'=>['target_receipt'=>$receipt->format('Y-m-d'),'indicative_order'=>$asOf->format('Y-m-d')],'warnings'=>$r['warnings'],'assumptions'=>$r['assumptions'],'policy_generation'=>$r]);
    }

    /** Deriva UUID estables exclusivamente de la identidad durable y del paso interno. @param string $key Clave del paso. @return string UUID determinista para reintentos. */
    private function uuid(string $key): string {$hash=hash('sha256',$key);return substr($hash,0,8).'-'.substr($hash,8,4).'-'.substr($hash,12,4).'-'.substr($hash,16,4).'-'.substr($hash,20,12);}
    /**
     * Suma decisiones incluidas por moneda; nunca mezcla monedas ni presenta subtotal incompleto como definitivo.
     * @param ProposalGeneration $generation Ejecución.
     * @return array Totales exactos por moneda, costos faltantes y presupuesto optativo sin asignación automática.
     */
    public function budget(ProposalGeneration $generation): array
    {
        $totals=[];$missing=0;$undecided=0;
        foreach(ProposalGenerationItem::where('generation_id',$generation->id)->select('id','decision')->lazyById(100) as $row){$d=$row->decision;if($d===null){$undecided++;continue;}if($d['excluded']){continue;}$cost=$d['cost']??null;if(($cost['subtotal']??null)===null||empty($cost['currency'])){$missing++;continue;}$currency=$cost['currency'];$totals[$currency]=(string)BigDecimal::of($totals[$currency]??'0')->plus($cost['subtotal']);}
        $options=$generation->snapshot['options'];$amount=$options['budget_amount']??null;$currency=$options['budget_currency']??null;$exceeded=null;
        if($amount!==null&&$currency!==null){$exceeded=BigDecimal::of($totals[$currency]??'0')->isGreaterThan($amount);}
        return ['totals_by_currency'=>$totals,'missing_costs'=>$missing,'undecided'=>$undecided,'definitive'=>$missing===0&&$undecided===0,'budget_amount'=>$amount,'budget_currency'=>$currency,'known_subtotal_exceeds_budget'=>$exceeded,'warnings'=>['Los importes desconocidos no son cero. No hay conversión de monedas ni reparto automático del presupuesto.']];
    }
}
