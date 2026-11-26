<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\InventoryParameterData;
use Asisnet\Replenishment\Models\{GenerationAttempt,ParameterApplication,ParameterReview,ProposalGeneration,ProposalGenerationItem};
use Brick\Math\BigDecimal;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use LogicException;

/** Coordina comparación por bloques y revisiones inmutables dentro de una generación autorizada. */
final class InventoryParameterReviews
{
    /** @param AdapterRegistry $adapters Fuentes registradas. @param InventoryParameterCalculator $calculator Reglas de niveles.
     * @param PersistenceConnection $own Conexión transaccional propia. */
    public function __construct(private AdapterRegistry $adapters,private InventoryParameterCalculator $calculator,
        private PersistenceConnection $own) {}

    /** Obtiene una página ordenada por código dentro del proveedor de origen.
     * @param ProposalGeneration $generation Ámbito resuelto en el consumidor. @param array $filters Filtros revisados.
     * @param int $page Número de página. @param string $path Ruta para los enlaces. @return LengthAwarePaginator Productos revisables. */
    public function page(ProposalGeneration $generation,array $filters,int $page,string $path): LengthAwarePaginator
    {
        $size=25;
        $query=ProposalGenerationItem::where('generation_id',$generation->id)->whereNotNull('result')->orderBy('product_id')->orderBy('id');
        if (($filters['code'] ?? '')!=='') {
            $literal=strtr($filters['code'],['!'=>'!!','%'=>'!%','_'=>'!_']);
            $query->whereRaw("product_id LIKE ? ESCAPE '!'",['%'.$literal.'%']);
        }
        if (($filters['description'] ?? '')==='' && ($filters['comparison'] ?? 'all')==='all') {
            $result=$query->paginate($size,['*'],'page',$page);
            $rows=$this->rows($generation,$result->items(),(bool)($filters['updated'] ?? false));
            $result->setCollection(collect(array_values($rows)));
            return $result->withPath($path)->appends($filters);
        }
        $total=0;
        $visible=[];
        $query->chunk(50,function ($items) use ($generation,$filters,$size,$page,&$visible,&$total): void {
            foreach ($this->rows($generation,$items->all(),(bool)($filters['updated'] ?? false)) as $row) {
                if (mb_stripos((string)$row['snapshot']['description'],$filters['description'] ?? '')===false
                    || !in_array($filters['comparison'] ?? 'all',$row['categories'],true)) { continue; }
                if (intdiv($total++,$size)===$page-1) { $visible[]=$row; }
            }
        });
        return new LengthAwarePaginator($visible,$total,$size,$page,['path'=>$path,'query'=>$filters]);
    }

    /** Carga sólo la última revisión de cada línea y lee en bloque los productos aún pendientes.
     * @param ProposalGeneration $generation Generación congelada. @param array $items Líneas del mismo origen.
     * @param bool $updated Permite abrir una comparación nueva si el análisis guardado cambió.
     * @return array Filas por identidad con huella para control optimista. */
    private function rows(ProposalGeneration $generation,array $items,bool $updated=false): array
    {
        if (!$items) { return []; }
        $ids=array_map(static fn($item)=>$item->id,$items);
        $latest=ParameterReview::whereIn('id',ParameterReview::selectRaw('MAX(id)')->whereIn('item_id',$ids)->groupBy('item_id'))->get()->keyBy('item_id');
        $applications=$latest->isEmpty()?collect():ParameterApplication::whereIn('id',
            ParameterApplication::selectRaw('MAX(id)')->whereIn('review_id',$latest->pluck('id'))->groupBy('review_id')
        )->get(['id','review_id','status','diagnosis','actor_id','updated_at'])->keyBy('review_id');
        $blocked=$latest->isEmpty()?collect():ParameterReview::whereIn('item_id',$ids)->whereIn('id',
            ParameterApplication::select('review_id')->where(function ($query) {
                $query->where('status','pending')->orWhere('diagnosis','uncertain');
            })
        )->pluck('item_id')->flip();
        $codes=$pendingIds=$changed=[];
        foreach ($items as $item) {
            $prior=$latest[$item->id] ?? null;
            $changed[$item->id]=$prior!==null && $prior->snapshot['source_hash']!==$this->sourceHash($item);
            if ($prior===null || ($updated && $changed[$item->id])) { $codes[]=$item->product_id; $pendingIds[]=$item->id; }
        }
        $masters=$codes?$this->adapters->resolve('inventory_parameters')->read($codes):[];
        $completed=$pendingIds ? GenerationAttempt::whereIn('item_id',$pendingIds)->where('stage','processed')
            ->groupBy('item_id')->selectRaw('item_id, MAX(updated_at) AS completed')->pluck('completed','item_id') : [];
        $rows=[];
        foreach ($items as $item) {
            $review=$latest[$item->id] ?? null;
            $version=$review?->revision ?? 0;
            $fresh=$updated && $changed[$item->id];
            if ($fresh) { $review=null; }
            $snapshot=$review?->snapshot;
            if ($snapshot===null) {
                $plan=$item->result;
                if (($plan['product_id'] ?? null)!==$item->product_id || ($plan['supplier_id'] ?? null)!==$generation->supplier_id
                    || ($plan['location_id'] ?? null)!==$generation->location_id) { $plan=[]; }
                $snapshot=$this->calculator->compare($plan,$masters[$item->product_id] ?? null);
                $snapshot['evidence']['policy'] ??= $generation->snapshot['policy'] ?? null;
                $snapshot['evidence']['as_of'] ??= $generation->snapshot['as_of'] ?? null;
                $snapshot['evidence']['calculated_at']=($item->technical_state==='processed') ? ($completed[$item->id] ?? null) : null;
                $snapshot['evidence']['statistics_preparation']=$plan['statistics_preparation'] ?? null;
                $snapshot['product']=$item->product_id;
                $snapshot['location']=$generation->location_id;
                $snapshot['proposal_id']=$generation->proposal_id;
                $snapshot['supplier']=$generation->supplier_id;
                $snapshot['generation_id']=$generation->id;
                $snapshot['item_id']=$item->id;
                $snapshot['source_hash']=$this->sourceHash($item);
            }
            $known=array_filter($snapshot['suggested'],static fn($value)=>$value!==null);
            $different=false;
            foreach ($known as $field=>$value) {
                $current=$snapshot['current'][$field];
                if ($current===null || !BigDecimal::of($current)->isEqualTo($value)) { $different=true; break; }
            }
            $categories=['all'];
            if ($known) { $categories[]='available'; }
            if (count($known)<6) { $categories[]='insufficient'; }
            if ($different) { $categories[]='different'; }
            elseif (count($known)===6) { $categories[]='equal'; }
            $rows[$item->id]=['id'=>$item->id,'revision'=>$version,'status'=>$review?->status ?? 'pending',
                'token'=>hash('sha256',json_encode([$snapshot,$version],JSON_THROW_ON_ERROR)),'snapshot'=>$snapshot,
                'application_blocked'=>$blocked->has($item->id),'review_id'=>$review?->id,'application'=>$review ? ($applications[$review->id] ?? null) : null,
                'new_analysis'=>$changed[$item->id],'updated'=>$fresh,'categories'=>$categories,'decisions'=>$review?->decisions,'reason'=>$review?->reason,'actor'=>$review?->actor_id,
                'reviewed_at'=>$review?->created_at?->toIso8601String()];
        }
        return $rows;
    }

    /** Identifica el resultado efectivo sin confiar en datos suministrados por el navegador.
     * @param ProposalGenerationItem $item Línea propia. @return string Huella del resultado persistido. */
    private function sourceHash(ProposalGenerationItem $item): string
    {
        return hash('sha256',$item->getRawOriginal('revised_result') ?? $item->getRawOriginal('result') ?? '');
    }

    /** Guarda revisiones individuales o un lote explícito de la página. Una revisión previa queda fuera del lote.
     * @param ProposalGeneration $generation Ámbito autorizado. @param array $entries IDs, huellas y elecciones individuales.
     * @param string $action individual, keep o accept. @param string $actor Usuario autenticado. @param string|null $reason Justificación.
     * @return array Contadores confirmados. @throws InvalidArgumentException Ante datos inválidos.
     * @throws LogicException Ante un cambio concurrente en el maestro, la evidencia o las decisiones. */
    public function save(ProposalGeneration $generation,array $entries,string $action,string $actor,?string $reason): array
    {
        if (!$entries || count($entries)>25 || !in_array($action,['individual','accept','keep'],true)
            || ($action==='individual' && count($entries)!==1) || trim($actor)==='' || mb_strlen($actor)>100) {
            throw new InvalidArgumentException('Seleccione una operación de revisión válida.');
        }
        $ids=array_column($entries,'id');
        $items=ProposalGenerationItem::where('generation_id',$generation->id)->whereNotNull('result')->whereIn('id',$ids)->orderBy('id')->get();
        if ($items->count()!==count($entries)) { throw new InvalidArgumentException('La selección contiene líneas ajenas, repetidas o pendientes de cálculo.'); }
        $rows=$this->rows($generation,$items->all(),$action==='individual' && ($entries[0]['updated'] ?? false));
        $prepared=[];
        $skipped=0;
        foreach ($entries as $entry) {
            $row=$rows[$entry['id']];
            if ($action!=='individual' && $row['revision']>0) { $skipped++; continue; }
            if (!hash_equals($row['token'],$entry['token'])) { throw new LogicException('La fotografía o la revisión cambió. Recargue los valores.'); }
            $snapshot=$row['snapshot'];
            $choices=$entry['choices'] ?? [];
            if ($action!=='individual') {
                foreach (InventoryParameterData::FIELDS as $field) {
                    $choices[$field]=['mode'=>$action==='accept' && $snapshot['suggested'][$field]!==null?'suggested':'keep'];
                }
            }
            try { $decision=$this->calculator->decide($snapshot,$choices); }
            catch (InvalidArgumentException $error) {
                if ($action!=='accept') { throw $error; }
                $skipped++; continue;
            }
            if ($action==='accept' && (!$snapshot['compatible']
                || !in_array('available',$row['categories'],true) || $decision['status']==='not_applicable')) { $skipped++; continue; }
            $prepared[$row['id']]=[$row,$decision];
        }
        // Las revisiones históricas siguen siendo legibles si desaparece el maestro. Antes de aprobar, comprobar la fotografía vigente.
        $verify=array_filter($prepared,static fn($pair)=>$pair[0]['revision']>0 && !$pair[0]['updated'] && $pair[1]['status']==='approved');
        if ($verify) {
            $masters=$this->adapters->resolve('inventory_parameters')->read(array_map(static fn($pair)=>$pair[0]['snapshot']['product'],$verify));
            foreach ($verify as [$row]) {
                $snapshot=$row['snapshot'];
                $master=$masters[$snapshot['product']] ?? null;
                if (!$master || $master->unit!==$snapshot['current_unit'] || $master->scale!==$snapshot['scale'] || $master->values!==$snapshot['current']) {
                    throw new LogicException('El maestro cambió desde la revisión. Use una evaluación actualizada.');
                }
            }
        }
        ksort($prepared,SORT_NUMERIC);
        return $this->own->transaction(function () use ($generation,$prepared,$actor,$reason,$skipped) {
            foreach ($prepared as $id=>[$row,$decision]) {
                $item=ProposalGenerationItem::where('generation_id',$generation->id)->lockForUpdate()->findOrFail($id);
                $last=ParameterReview::where('item_id',$id)->orderByDesc('revision')->lockForUpdate()->first();
                if (($last?->revision ?? 0)!==$row['revision'] || $this->sourceHash($item)!==$row['snapshot']['source_hash']) {
                    throw new LogicException('La línea o su revisión cambió. Consulte una generación actualizada.');
                }
                ParameterReview::create(['item_id'=>$id,'revision'=>$row['revision']+1,'actor_id'=>$actor,
                    'snapshot'=>$row['snapshot'],'reason'=>$reason]+$decision);
            }
            return ['saved'=>count($prepared),'skipped'=>$skipped];
        });
    }
}
