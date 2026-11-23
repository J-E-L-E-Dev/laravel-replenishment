<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\DashboardScope;
use Asisnet\Replenishment\Models\{PurchaseProposal,PurchaseProposalItem,ProposalEvent,ProposalGeneration,ProposalGenerationItem};
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use LogicException;

/** Consulta operativa de solo lectura: distingue cabeceras, líneas y productos únicos. */
final class ReplenishmentDashboardQuery
{
    /** Recibe exclusivamente persistencia propia. @param PersistenceConnection $connection Destino configurado. */
    public function __construct(private PersistenceConnection $connection) {}
    /** Comprueba instalación sin migrar automáticamente. @return void @throws LogicException Si falta la ampliación de generación. */
    public function assertInstalled(): void
    {
        $schema=$this->connection->connection()->getSchemaBuilder();
        if (!$schema->hasTable('replenishment_generations')) { throw new LogicException('Dashboard no disponible: falta la migración propia 000006 de generación por política. No se ejecutó ninguna migración.'); }
        if (!$schema->hasTable('replenishment_sales_runs') || !$schema->hasTable('replenishment_sales_days') || !$schema->hasTable('replenishment_sales_unit_totals') || !$schema->hasColumn('replenishment_policies','demand_settings') || !$schema->hasColumn('replenishment_generation_items','technical_state') || !$schema->hasTable('replenishment_generation_attempts')) {
            throw new LogicException('Despliegue pendiente. Compruebe las migraciones propias 000007, 000008 y 000009 en la conexión del paquete.');
        }
    }
    /** Aplica filtros a cabeceras, nunca al ERP. @param Builder $query Modelo propio. @param DashboardScope $scope Ámbito validado. @param bool $proposal Incluye estado de propuesta. @return Builder Consulta filtrada. */
    private function scoped(Builder $query, DashboardScope $scope, bool $proposal=false): Builder
    {
        $query->where('location_id',$scope->location)->where('created_at','>=',$scope->from)->where('created_at','<',$scope->until);
        if ($scope->supplier!==null) { $query->where('supplier_id',$scope->supplier); }
        if ($proposal && $scope->status!==null) { $query->where('status',$scope->status); }
        return $query;
    }
    /** Agrega indicadores sin cargar resultados JSON ni repetir productos entre ejecuciones. @param DashboardScope $scope Filtros. @return array Conteos con ámbito explícito. */
    public function indicators(DashboardScope $scope): array
    {
        $this->assertInstalled();
        $proposals=$this->scoped(PurchaseProposal::query(),$scope,true)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total','status')->map(fn($n)=>(int)$n)->all();
        $generations=$this->scoped(ProposalGeneration::query(),$scope);
        $states=(clone $generations)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total','status')->map(fn($n)=>(int)$n)->all();
        $items=ProposalGenerationItem::whereIn('generation_id',(clone $generations)->select('id'));
        $technical=(clone $items)->whereNotNull('technical_state')->selectRaw('technical_state, COUNT(*) AS total')->groupBy('technical_state')->pluck('total','technical_state');
        $origins=(clone $items)->whereNotNull('statistics_origin')->selectRaw('statistics_origin, COUNT(*) AS total')->groupBy('statistics_origin')->pluck('total','statistics_origin');
        $uncreated=0;
        foreach ((clone $generations)->where('status','initializing')->select(['id','selection_manifest','freeze_cursor'])->lazyById(25) as $header) {
            $uncreated+=count($header->selection_manifest ?? [])-$header->freeze_cursor;
        }
        $pending=$uncreated+(clone $items)->where('status','pending')->where(function($q){$q->whereNull('technical_state')->orWhere('technical_state','pending');})->count();
        $terminal=(clone $items)->where('status','!=','pending')->where(function($q){$q->whereNull('technical_state')->orWhereNotIn('technical_state',['preparing','calculating']);});
        return ['work'=>['pending'=>$pending,'active'=>(int)($technical['preparing']??0)+(int)($technical['calculating']??0),
            'recoverable_error'=>(int)($technical['recoverable_error']??0),'intervention_required'=>(int)($technical['intervention_required']??0),
            'reused'=>(int)($origins['reused']??0),'refreshed'=>(int)($origins['refreshed']??0),
            'undecided_lines'=>(clone $terminal)->whereNull('decision')->count(),
            'undecided_products'=>(clone $terminal)->whereNull('decision')->distinct()->count('product_id')],'scope'=>$scope->toArray(),'proposals'=>$proposals,'proposal_total'=>array_sum($proposals),'generations'=>$states,'generation_total'=>array_sum($states),'finished'=>($states['ready']??0)+($states['saved']??0),'resumable'=>($states['initializing']??0)+($states['processing']??0)+($states['saving']??0),
            'generations_with_errors'=>(clone $items)->where('status','error')->distinct()->count('generation_id'),
            'analysis_lines'=>(clone $terminal)->count(),'unique_products'=>(clone $terminal)->distinct()->count('product_id'),'insufficient_lines'=>(clone $items)->where('status','insufficient_data')->count()];
    }
    /** Pagina propuestas con conteos y auditoría acotada, sin consultar responsables en el ERP. @param DashboardScope $scope Filtros. @param int $page Página positiva. @return mixed Paginador de 25 cabeceras. @throws InvalidArgumentException Ante página inválida. */
    public function proposals(DashboardScope $scope,int $page=1): mixed
    {
        if($page<1){throw new InvalidArgumentException('Página inválida.');}
        $rows=$this->scoped(PurchaseProposal::query(),$scope,true)->orderByDesc('id')->paginate(25,['*'],'proposals_page',$page);
        $ids=$rows->getCollection()->pluck('id');
        $products=PurchaseProposalItem::whereIn('purchase_proposal_id',$ids)->whereNull('removed_at')->selectRaw('purchase_proposal_id, COUNT(DISTINCT product_id) AS total')->groupBy('purchase_proposal_id')->pluck('total','purchase_proposal_id');
        $latest=ProposalEvent::whereIn('purchase_proposal_id',$ids)->whereIn('action',['add','adjust','remove','notes'])->selectRaw('MAX(id) AS id')->groupBy('purchase_proposal_id');
        $events=ProposalEvent::whereIn('id',$latest)->get(['id','purchase_proposal_id','actor_id','action'])->keyBy('purchase_proposal_id');
        $generations=ProposalGeneration::whereIn('proposal_id',$ids)->get(['id','proposal_id','snapshot'])->groupBy('proposal_id');
        foreach($rows as $row){$row->setAttribute('items_count',(int)($products[$row->id]??0));$row->setAttribute('last_event_actor',$events->get($row->id)?->actor_id);$related=$generations->get($row->id);$row->setAttribute('policy_name',$related?->count()===1?($related->first()->snapshot['policy']['name']??null):null);}
        return $rows;
    }
    /** Pagina ejecuciones y agrupa sus líneas en una sola consulta adicional. @param DashboardScope $scope Filtros. @param int $page Página positiva. @return mixed Paginador con contadores reales. */
    public function generations(DashboardScope $scope,int $page=1): mixed
    {
        if($page<1){throw new InvalidArgumentException('Página inválida.');}
        $rows=$this->scoped(ProposalGeneration::query(),$scope)->orderByDesc('id')->paginate(25,['*'],'generations_page',$page);
        $counts=ProposalGenerationItem::whereIn('generation_id',$rows->getCollection()->pluck('id'))->selectRaw('generation_id, status, technical_state, COUNT(*) AS total')->groupBy('generation_id','status','technical_state')->get()->groupBy('generation_id');
        foreach($rows as $row){$groups=$counts->get($row->id,collect());$c=$groups->groupBy('status')->map(fn($states)=>(int)$states->sum('total'))->all();$recalculating=$groups->where('status','!=','pending')->whereIn('technical_state',['preparing','calculating'])->sum('total');$row->setAttribute('counts',$c);$row->setAttribute('selected',$row->status==='initializing'?count($row->selection_manifest ?? []):array_sum($c));$row->setAttribute('processed',array_sum($c)-($c['pending']??0)-$recalculating);$row->setAttribute('policy_name',$row->snapshot['policy']['name']??null);$row->setAttribute('results',array_sum(array_intersect_key($c,array_flip(['purchase_needed','no_purchase_needed','extended_coverage_candidate','declining_demand_review','insufficient_data','configuration_required']))));unset($row->snapshot);}
        return $rows;
    }
    /** Lee decisiones de una sola generación filtrada; suma únicamente subtotales persistidos por moneda. @param DashboardScope $scope Ámbito. @param int $id Generación seleccionada. @return array Conteos y costos parciales sin reinterpretar evidencia. */
    public function decisions(DashboardScope $scope,int $id): array
    {
        $this->assertInstalled();
        $generation=$this->scoped(ProposalGeneration::query(),$scope)->findOrFail($id);
        $counts=[];$excluded=0;$undecided=0;$missing=0;$totals=[];
        foreach(ProposalGenerationItem::where('generation_id',$id)->select(['id','status','decision'])->lazyById(200) as $row){$counts[$row->status]=($counts[$row->status]??0)+1;$decision=$row->decision;if($decision===null){$undecided++;continue;}if($decision['excluded']??false){$excluded++;continue;}$cost=$decision['cost']??null;if(!isset($cost['subtotal'],$cost['currency'])){$missing++;continue;}$currency=$cost['currency'];$totals[$currency]=(string)\Brick\Math\BigDecimal::of($totals[$currency]??'0')->plus($cost['subtotal']);}
        return ['generation_id'=>$generation->id,'counts'=>$counts,'excluded'=>$excluded,'undecided'=>$undecided,'totals_by_currency'=>$totals,'missing_costs'=>$missing,'partial'=>$missing>0||$undecided>0];
    }
    /** Recupera el último diagnóstico persistido de un producto en el ámbito; no reclasifica tendencias. @param DashboardScope $scope Ámbito. @param string $product Código exacto. @param int|null $generationId Generación seleccionada en el listado. @return array|null Evidencia original identificada. */
    public function trend(DashboardScope $scope,string $product,?int $generationId=null): ?array
    {
        $query=ProposalGenerationItem::whereIn('generation_id',$this->scoped(ProposalGeneration::query(),$scope)->select('id'))->where('product_id',$product);
        $row=$generationId===null?$query->whereNotNull('result')->orderByDesc('id')->first():$query->where('generation_id',$generationId)->firstOrFail();
        if ($row===null) {return null;}
        $attempt=\Asisnet\Replenishment\Models\GenerationAttempt::where('item_id',$row->id)->whereIn('stage',['processed','recoverable_error','intervention_required'])->latest('id')->first(['id','updated_at']);
        return ['attempt_id'=>$attempt?->id,'calculation_recorded_at'=>$attempt?->updated_at,'generation_id'=>$row->generation_id,'item_id'=>$row->id,'created_at'=>$row->created_at,'trend'=>$row->result['trend']??null,'history'=>$row->result['history']??null,'demand'=>$row->result['demand_after_receipt']??null,'assumptions'=>$row->result['assumptions']??[],'decision'=>$row->decision,'diagnosis'=>$row->diagnosis,'technical_state'=>$row->technical_state,'planning'=>array_intersect_key($row->result??[],array_flip(['statistics_preparation','algorithm_version','as_of','base_unit','policy','demand_evidence','demand_before_receipt','demand_after_receipt','availability','lead_time','protection_days','protection_demand','extended_coverage','packages','presentation','balance','warnings','status','stage','message']))];
    }
}
