<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\{DemandForecast,SeasonalPattern,ProductMetric};
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/** Lectura de versiones analíticas persistidas; nunca mezcla auditorías ni genera estimaciones. */
final class DashboardAnalysisQuery
{
    /** Selecciona modelo histórico permitido. @param string $kind forecast o seasonality. @return Builder Consulta propia. @throws InvalidArgumentException Ante categoría no admitida. */
    private function query(string $kind): Builder { return match($kind){'forecast'=>DemandForecast::query(),'seasonality'=>SeasonalPattern::query(),default=>throw new InvalidArgumentException('Tipo de análisis inválido.')}; }
    /** Pagina anclas históricas por fecha y versión; los enlaces usan una identidad persistida. @param string $kind Tipo. @param string $product Producto. @param string $location Ubicación. @param int $page Página. @return mixed Paginador de versiones, máximo veinte. */
    public function versions(string $kind,string $product,string $location,int $page=1): mixed
    {
        if($page<1||$product===''||$location===''){throw new InvalidArgumentException('Ámbito o página inválidos.');}
        $date=$kind==='forecast'?'generated_at':'calculated_at';
        return $this->query($kind)->where('product_id',$product)->where('location_id',$location)->selectRaw('MAX(id) AS id, '.$date.', algorithm_version')->groupBy($date,'algorithm_version')->orderByDesc($date)->orderByDesc('id')->paginate(20,['*'],'versions_page',$page);
    }
    /** Recupera un lote coherente y sólo atribuye calidad mediante vínculo verificable de IDs. @param string $kind Tipo. @param string $product Producto. @param string $location Ubicación. @param int|null $id Ancla; NULL selecciona la más reciente. @return array Filas, calidad y auditoría original o desconocimiento explícito. */
    public function read(string $kind,string $product,string $location,?int $id=null): array
    {
        if($product===''||$location===''){throw new InvalidArgumentException('Seleccione producto y ubicación.');}
        $date=$kind==='forecast'?'generated_at':'calculated_at';$idsKey=$kind==='forecast'?'demand_forecast_ids':'seasonal_pattern_ids';$auditKey=$kind==='forecast'?'forecast_audit':'seasonality';
        $base=$this->query($kind)->where('product_id',$product)->where('location_id',$location);
        $anchor=$id===null?(clone $base)->orderByDesc($date)->orderByDesc('id')->first():(clone $base)->findOrFail($id);
        if($anchor===null){return ['rows'=>[],'quality'=>null,'audit'=>null,'complete_set'=>false,'chart_compatible'=>false,'source'=>$kind];}
        $audit=null;$ids=null;$metricId=null;
        $metrics=ProductMetric::where('product_id',$product)->where('location_id',$location)->where('calculated_at',$anchor->getRawOriginal($date))->where('algorithm_version',$anchor->algorithm_version)->orderByDesc('id')->limit(101)->get();
        foreach($metrics->count()>100?[]:$metrics as $metric){$summary=$metric->observed_sales_summary??[];$linked=$summary[$idsKey]??[];if(in_array((int)$anchor->id,array_map('intval',$linked),true)){if($ids!==null){$ids=null;$audit=null;break;}$ids=$linked;$audit=$summary[$auditKey]??null;$metricId=$metric->id;}}
        // Sin un vínculo inequívoco, una sola fila no acredita una generación anual o un horizonte completo.
        $rows=$ids===null?collect([$anchor]):(clone $base)->where($date,$anchor->getRawOriginal($date))->where('algorithm_version',$anchor->algorithm_version)->whereIn('id',$ids)->orderBy($kind==='forecast'?'period_start':'period_number')->limit(121)->get();
        if($ids!==null&&($rows->count()!==count($ids)||count($ids)>120)){$audit=null;$rows=collect([$anchor]);$ids=null;}
        $output=[];
        foreach($rows as $row){$value=$row->toArray();$value['quality']=null;$value['base_unit']=$audit['base_unit']??null;
            if($kind==='forecast'&&$audit!==null){$matches=array_values(array_filter($audit['forecasts']??[],fn($f)=>($f['period_start']??null)===$row->period_start->format('Y-m-d')&&($f['period_end']??null)===$row->period_end->format('Y-m-d')&&($f['method']??null)===$row->method));if(count($matches)===1){$value['quality']=$matches[0]['quality']??null;}}
            elseif($kind==='seasonality'){$value['quality']=$row->period_class;}
            $output[]=$value;
        }
        $complete=$ids!==null;$chartCompatible=$complete;
        if($kind==='forecast'){$seen=[];foreach($rows as $row){$month=$row->period_start->format('Y-m');if($row->granularity!=='monthly'||$row->period_start->format('d H:i:s')!=='01 00:00:00'||$row->period_end->format('Y-m-d H:i:s')!==$row->period_start->modify('+1 month')->format('Y-m-d H:i:s')||isset($seen[$month])){$chartCompatible=false;}$seen[$month]=true;}if(empty($audit['base_unit'])){$chartCompatible=false;}}
        if($kind==='seasonality'){$numbers=array_column($output,'period_number');sort($numbers);$complete=$complete&&$numbers===range(1,12)&&count(array_filter($output,fn($r)=>$r['period_type']!=='monthly'))===0;}
        return ['anchor_id'=>$anchor->id,'generated_at'=>$anchor->getAttribute($date)->format(DATE_ATOM),'algorithm_version'=>$anchor->algorithm_version,'rows'=>$output,'audit'=>$audit,'audit_metric_id'=>$ids===null?null:$metricId,'quality'=>$audit['quality']??null,'complete_set'=>$complete,'chart_compatible'=>$kind==='seasonality'?$complete:$chartCompatible,'source'=>$kind,'warnings'=>$ids===null?['No hay un vínculo inequívoco de auditoría: se muestra sólo el registro seleccionado, sin formar un conjunto.']:[]];
    }
}
