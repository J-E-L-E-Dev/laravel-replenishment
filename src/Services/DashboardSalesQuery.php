<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ProductData;
use Asisnet\Replenishment\Models\CommercialSalesRun;
use DateTimeImmutable;
use Asisnet\Replenishment\Models\ProductMetric;
use DateTimeZone;
use InvalidArgumentException;

/** Series comerciales de caché; reutiliza el calendario existente sin acceder al origen. */
final class DashboardSalesQuery
{
    /** Recibe agregadores puros y almacenamiento materializado. @param ObservedDemandStore $store Caché. @param SalesHistoryBreakdownService $history Calendario compartido. */
    public function __construct(private ObservedDemandStore $store,private SalesHistoryBreakdownService $history) {}
    /** Lee hasta 24 meses bajo demanda, conserva meses desconocidos y parciales. @param string $product Producto exacto. @param string $location Destino. @param string $from Primer mes YYYY-MM. @param string $until Mes final exclusivo YYYY-MM. @param string $timezone Zona operativa. @param DateTimeImmutable $now Corte actual. @param int|null $runId Publicación histórica explícita. @param ProductData|null $master Maestro verificado para seleccionar la publicación vigente. @return array Serie mensual y resúmenes anuales sin inventar semanas. @throws InvalidArgumentException Ante intervalo inválido. */
    public function read(string $product,string $location,string $from,string $until,string $timezone,DateTimeImmutable $now,?int $runId=null,?ProductData $master=null): array
    {
        $zone=new DateTimeZone($timezone);$start=DateTimeImmutable::createFromFormat('!Y-m',$from,$zone);$end=DateTimeImmutable::createFromFormat('!Y-m',$until,$zone);
        if(!$start||!$end||$start->format('Y-m')!==$from||$end->format('Y-m')!==$until||$start>=$end||$end>$start->modify('+24 months')||$product===''||$location===''){throw new InvalidArgumentException('Seleccione producto y un intervalo de hasta 24 meses.');}
        $cutoff=$now->setTimezone($zone)->setTime(0,0);if($start>=$cutoff||$end>$cutoff->modify('first day of next month')->setTime(0,0)){throw new InvalidArgumentException('Las ventas no admiten meses futuros.');}
        if ($runId!==null || app(CommercialProfile::class)->enabled()) {
            return $this->observations($product,$location,$start,min($end,$cutoff),$runId,$master);
        }
        $rows=[];$years=[];
        for ($cursor=$start;$cursor<min($end,$cutoff);$cursor=$stop) {
            $stop=min($end,$cursor->modify('first day of January next year'));
            $data=$this->store->read($product,$location,$cursor,min($stop,$cutoff),$timezone);
            $part=$this->history->breakdown($data,false,$stop);
            $rows=array_merge($rows,$part['rows']);
            $years[]=['year'=>$cursor->format('Y'),'summary'=>$part['summary'],'selected_interval_only'=>true];
        }
        $rows=$this->monthlyEvidence($product,$location,$timezone,$start,$end,$rows);
        $units=array_values(array_unique(array_filter(array_column($rows,'base_unit'))));
        return ['product_id'=>$product,'location_id'=>$location,'from'=>$from,'until'=>$until,'base_unit'=>count($units)===1?$units[0]:null,
            'unit_compatible'=>count($units)===1,'rows'=>$rows,'years'=>$years,'statistics'=>null,'source'=>'Caché diaria y métricas mensuales identificadas',
            'warnings'=>['La caché puede estar desactualizada. Ausencia de ventas no acredita ausencia de demanda.']];
    }

    /** Consulta observaciones por unidad sin ejecutar estimaciones. @param string $product Producto. @param string $location Ubicación.
     * @param DateTimeImmutable $start Inicio civil. @param DateTimeImmutable $end Fin exclusivo. @param int|null $runId Versión fijada.
     * @param ProductData|null $master Maestro actual, si fue verificado. @param bool $weekly Entrega semanas en vez de meses.
     * @return array Desglose por período y metadatos de una sola publicación.
     * @throws \InvalidArgumentException Si la consulta excede dos años. */
    public function observations(string $product,string $location,DateTimeImmutable $start,DateTimeImmutable $end,?int $runId=null,
        ?ProductData $master=null,bool $weekly=false): array
    {
        if ($start >= $end || $end>$start->modify('+24 months')) {
            throw new InvalidArgumentException('El historial requiere un intervalo de hasta 24 meses.');
        }
        $store=app(CommercialSalesStore::class);
        $data=$runId===null?$store->cached($product,$location,$start,$end,$start->getTimezone()->getName(),$master,snapshot:true)
            :$store->historicalWindow($runId,$product,$location,$start,$end);
        if ($runId===null && $master!==null && $data->runId===null && $data->effective>$start && $data->effective<$end) {
            $cover=$store->compatibleRun($data->profile,$data->effective,$end,$master);
            if ($cover!==null) {
                $data=$store->historicalWindow((int)$cover->id,$product,$location,$start,$end);
            }
        }
        $report=app(CommercialSalesReport::class)->breakdown($data);
        // Los lectores anteriores reciben una magnitud escalar sólo si pertenece a una única unidad acreditada.
        $scalar=static function(array $period): array {
            $buckets=$period['totals_by_unit']??[];
            return $period+(count($buckets)===1?array_intersect_key($buckets[0],array_flip(ObservedDemandCalculator::QUANTITIES))+['base_unit'=>$buckets[0]['unit']]
                :array_fill_keys([...ObservedDemandCalculator::QUANTITIES,'base_unit'],null));
        };
        $report['summary']=$scalar($report['summary']);
        $publication=$data->runId===null?null:CommercialSalesRun::findOrFail($data->runId)->only(['id','requested_start','effective_start','until_date','status']);
        $rows=[];
        foreach ($report[$weekly?'weeks':'months'] as $label=>$period) {
            $rows[]=['label'=>$label,'weeks_available'=>$data->runId!==null]+$scalar($period);
        }
        $years=[];
        foreach ($report['years'] as $year=>$period) {
            $years[]=['year'=>(string)$year,'summary'=>$scalar($period),'selected_interval_only'=>true];
        }
        unset($report['days'],$report['weeks'],$report['years'],$report['months']);
        $units=array_column($report['summary']['observed_by_unit'],'unit');
        $warnings=['La ausencia de movimientos observados no acredita demanda futura cero.'];
        if ($data->runId===null) {
            $warnings[]='Actualización necesaria. Falta una publicación compatible para este intervalo.';
        } elseif ($runId!==null || $master===null) {
            $warnings[]='Unidad y corte de la fotografía histórica. Vigencia actual sin verificar.';
        }
        if ($data->runId===null) {$report['calculated_at']=null;}
        return ['commercial'=>true,'product_id'=>$product,'location_id'=>$location,'from'=>$start->format('Y-m-d'),'until'=>$end->format('Y-m-d'),
            'publication'=>$publication,'sales_run_id'=>$data->runId,'statistics'=>$report,'summary'=>$report['summary'],
            'units'=>$units,'base_unit'=>count($units)===1?$units[0]:null,'source'=>$data->runId===null?'Actualización necesaria · publicaciones comerciales propias':'Publicaciones comerciales propias','warnings'=>$warnings,'rows'=>$rows,'years'=>$years];
    }

    /** Pagina únicamente cabeceras históricas del ámbito autorizado. @param string $product Producto exacto. @param string $location Destino.
     * @param int $page Página solicitada. @return mixed Paginador de versiones sin sus series diarias. */
    public function versions(string $product,string $location,int $page): mixed
    {
        $versions=CommercialSalesRun::where('product_id',$product)->where('location_id',$location)->orderByDesc('calculated_at')->orderByDesc('id')
            ->paginate(25,['id','calculated_at','profile','profile_hash','requested_start','effective_start','until_date','status'],'versions_page',$page);
        return $versions->through(static fn($run)=>$run->only(['id','profile_hash','requested_start','effective_start','until_date','status'])
            +['algorithm_version'=>$run->profile['aggregator'],'current_unit'=>$run->profile['current_unit'],'cutoff'=>$run->profile['cutoff'],
                'calculated_at'=>(new DateTimeImmutable($run->getRawOriginal('calculated_at'),new DateTimeZone('UTC')))->format(DATE_ATOM)]);

    }

    /** Expande el mes con una sola publicación. @param string $product Producto. @param string $location Ubicación.
     * @param int $year Año. @param int $month Mes. @param string $timezone Zona. @param DateTimeImmutable $now Corte actual.
     * @param int|null $runId Identidad de la publicación mensual. @param string|null $from Inicio de la consulta original.
     * @param string|null $until Fin exclusivo original. @return array Semanas civiles recortadas.
     * @throws InvalidArgumentException Si el mes queda fuera del intervalo o falta la identidad comercial. */
    public function weeks(string $product,string $location,int $year,int $month,string $timezone,DateTimeImmutable $now,
        ?int $runId=null,?string $from=null,?string $until=null): array
    {
        if ($runId===null && !app(CommercialProfile::class)->enabled()) {
            return $this->history->read($product,$location,$year,$month,$timezone,$now,false);
        }
        if (!$runId || $month<1 || $month>12) {throw new InvalidArgumentException('Seleccione el mes de una publicación comercial identificada.');}
        $zone=new DateTimeZone($timezone);
        $monthStart=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month),$zone);
        $start=$from===null?$monthStart:max($monthStart,new DateTimeImmutable($from,$zone));
        $end=min($monthStart->modify('+1 month'),$now->setTimezone($zone)->setTime(0,0));
        if ($until!==null) {$end=min($end,new DateTimeImmutable($until,$zone));}
        return $this->observations($product,$location,$start,$end,$runId,weekly:true);
    }

    /** Recupera meses resumidos verificables cuando no quedan días completos; nunca inventa semanas ni unidades. @param string $product Producto. @param string $location Destino. @param string $timezone Zona. @param DateTimeImmutable $from Inicio. @param DateTimeImmutable $until Fin. @param array $rows Meses de caché. @return array Meses con procedencia individual y detalle semanal disponible sólo desde caché. */
    private function monthlyEvidence(string $product,string $location,string $timezone,DateTimeImmutable $from,DateTimeImmutable $until,array $rows): array
    {
        foreach($rows as &$row){$row['source']='daily_cache';$row['weeks_available']=true;}unset($row);
        if(count(array_filter($rows,fn($row)=>$row['observation_status']!=='complete'))===0){return $rows;}
        $metrics=ProductMetric::where('product_id',$product)->where('location_id',$location)->where('algorithm_version',ObservedDemandCalculator::VERSION)->where('analysis_start','<',$until)->where('analysis_end','>',$from)->orderByDesc('calculated_at')->orderByDesc('id')->limit(25)->get();
        foreach($rows as &$row){if($row['observation_status']==='complete'||$row['partial_calendar_period']){continue;}
            foreach($metrics as $metric){$audit=$metric->observed_sales_summary??[];$month=$audit['months'][$row['label']]??null;
                if(($audit['product_id']??null)!==$product||($audit['location_id']??null)!==$location||($audit['timezone']??null)!==$timezone||($audit['origin']??null)!=='source'||($month['observation_status']??null)!=='complete'||($month['partial_calendar_period']??true)||($month['from']??null)!==$row['from']||($month['until']??null)!==$row['until']){continue;}
                $complete=true;foreach(ObservedDemandCalculator::QUANTITIES as $field){if(!isset($month[$field])){$complete=false;}}if(!$complete){continue;}
                $row=array_replace($row,$month,['source'=>'metric_monthly','metric_id'=>$metric->id,'calculated_at'=>$metric->calculated_at->format(DATE_ATOM),'base_unit'=>$month['base_unit']??null,'unknown_days'=>0,'weeks_available'=>false]);break;
            }
        }unset($row);return $rows;
    }
}

