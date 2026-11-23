<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\PurchaseProposal;
use Illuminate\Pagination\LengthAwarePaginator;
/** Consulta propuestas propias sin mezclar proveedores ni usar conexiones ERP. */
final class PurchaseProposalQuery
{
    /** Lista totales completos con filtros exactos. @param array $filters Proveedor, producto, estado, ubicación y fechas. @param int $page Página. @param string $path URL de enlaces. @return LengthAwarePaginator Página real de 25 propuestas. */
    public function paginate(array $filters,int $page,string $path): LengthAwarePaginator
    {
        $q=PurchaseProposal::query()->withCount(['items'=>fn($i)=>$i->whereNull('removed_at')]);
        foreach(['supplier_id','status','location_id'] as $key){if(!empty($filters[$key])){$q->where($key,$filters[$key]);}}
        if(!empty($filters['product_id'])){$q->whereHas('items',fn($i)=>$i->where('product_id',$filters['product_id'])->whereNull('removed_at'));}
        if(!empty($filters['from'])){$q->whereDate('created_at','>=',$filters['from']);}if(!empty($filters['until'])){$q->whereDate('created_at','<=',$filters['until']);}
        $total=(clone $q)->count();$page=max(1,min($page,max(1,(int)ceil($total/25))));return new LengthAwarePaginator($q->orderByDesc('id')->forPage($page,25)->get(),$total,25,$page,['path'=>$path,'query'=>$filters]);
    }
}
