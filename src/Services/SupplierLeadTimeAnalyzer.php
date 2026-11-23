<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Contracts\PurchaseHistorySource;
use Asisnet\Replenishment\DTO\PurchaseReconciliationData;
use Asisnet\Replenishment\DTO\LeadTimeCoverageData;
use Asisnet\Replenishment\DTO\SupplierLeadTimeOptions;
use Asisnet\Replenishment\DTO\SupplierLeadTimeData;
use Asisnet\Replenishment\DTO\SupplierLeadTimeObservationData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Analiza fechas de vínculos ya conciliados sin normalizar signos ni inferir cierre comercial. */
final class SupplierLeadTimeAnalyzer
{
    /** @var string Versión de reglas de evidencia y estadística. */
    public const VERSION='supplier-lead-time-1.0';
    /**
     * Recibe el validador común de intervalos civiles.
     * @param ObservedDemandCalculator $calendar Validador de fechas sin acceso a fuentes comerciales.
     */
    public function __construct(private ObservedDemandCalculator $calendar) {}
    /**
     * Lee órdenes acotadas; una fuente fallida o duplicada invalida el lote completo.
     * @param PurchaseHistorySource $source Contrato histórico aprobado.
     * @param string $productId Producto.
     * @param string $supplierId Proveedor solicitado.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio inclusivo de emisión.
     * @param DateTimeImmutable $until Corte exclusivo.
     * @param string $timezone Zona operativa.
     * @param SupplierLeadTimeOptions $options Reglas de suficiencia.
     * @param array<LeadTimeCoverageData> $coverage Comprobaciones externas reales; nunca generadas del estado del conciliador.
     * @param DateTimeImmutable|null $evaluatedAt Fecha de evaluación, por defecto actual.
     * @return SupplierLeadTimeData Estadísticas observadas y certificadas separadas.
     * @throws InvalidArgumentException Si los límites, alcance o evidencias suministradas son inválidos.
     */
    public function analyze(PurchaseHistorySource $source,string $productId,string $supplierId,string $locationId,DateTimeImmutable $from,DateTimeImmutable $until,
        string $timezone,SupplierLeadTimeOptions $options,array $coverage=[],?DateTimeImmutable $evaluatedAt=null): SupplierLeadTimeData
    {
        [$from,$until]=$this->calendar->bounds($from,$until,$timezone);$evaluatedAt??=new DateTimeImmutable('now',new DateTimeZone($timezone));
        if($productId===''||$supplierId===''||$locationId===''||$until>$evaluatedAt){throw new InvalidArgumentException('Use alcance explícito y un corte no posterior a la evaluación.');}
        $proofs=[];foreach($coverage as $proof){if(!$proof instanceof LeadTimeCoverageData||isset($proofs[$proof->key()])){throw new InvalidArgumentException('La evidencia de cobertura debe tener identidades únicas.');}if($proof->until>$evaluatedAt){throw new InvalidArgumentException('La cobertura no puede acreditar fechas futuras.');}$proofs[$proof->key()]=$proof;}
        $observations=[];$scanned=0;$other=0;$failure=null;$receiptOwners=[];$identities=new EventIdentityIndex();
        try{
            foreach($source->between($productId,$locationId,$from,$until) as $row){
                if(++$scanned>$options->maximumOrders){throw new RuntimeException('order_limit_exceeded');}
                if(!$row instanceof PurchaseReconciliationData||$row->productId!==$productId||$row->order->locationId!==$locationId){throw new RuntimeException('scope_mismatch');}
                $identities->add(json_encode([$row->order->document,$row->order->number,$productId,$locationId],JSON_THROW_ON_ERROR));
                if($row->order->supplierId!==$supplierId){$other++;continue;}
                $key=json_encode([$row->order->document,$row->order->number,$productId,$supplierId,$locationId],JSON_THROW_ON_ERROR);
                foreach($row->links as $link){if($link->verified){$id=$link->receipt->movement->id;if(isset($receiptOwners[$id])&&$receiptOwners[$id]!==$key){throw new RuntimeException('duplicate_receipt_identity');}$receiptOwners[$id]=$key;}}
                $observations[]=$this->observation($row,$from,$until,$timezone,$proofs[$key]??null,$key);
            }
            $identities->finish();
        }catch(\Throwable $error){$failure=in_array($error->getMessage(),['scope_mismatch','order_limit_exceeded','duplicate_identity','duplicate_receipt_identity'],true)?$error->getMessage():'source_failure';$observations=[];}
        finally{$identities->close();}
        $first=[];$certified=[];$complete=[];$deviations=[];$linked=0;$partial=0;$covered=0;$causes=[];
        foreach($observations as $observation){$e=$observation->evidence;
            $linked+=(int)$e['documentary_link_verified'];$partial+=(int)$e['partial_receipt_observed'];$covered+=(int)$e['observed_quantity_coverage'];
            foreach(array_unique(array_merge($e['exclusions'],$e['limitations'])) as $cause){$causes[$cause]=($causes[$cause]??0)+1;}
            if($e['first_observed_days']!==null){$first[]=['days'=>$e['first_observed_days'],'at'=>$e['first_received_at']];}
            if($e['first_receipt_certified']){$certified[]=['days'=>$e['first_observed_days'],'at'=>$e['first_received_at']];}
            if($e['complete_receipt_days']!==null){$complete[]=['days'=>$e['complete_receipt_days'],'at'=>$e['complete_received_at']];}
            if($e['expected_to_first_days']!==null){$deviations[]=['days'=>$e['expected_to_first_days'],'at'=>$e['first_received_at']];}
        }
        $n=count($observations);$summary=['reading_status'=>$failure===null?'complete':'incomplete','failure_cause'=>$failure,
            'orders_scanned'=>$failure===null?$scanned:null,'orders_other_suppliers'=>$failure===null?$other:null,'orders_evaluated'=>$failure===null?$n:null,
            'orders_with_verified_link'=>$failure===null?$linked:null,'orders_accepted'=>$failure===null?count($first):null,'orders_excluded'=>$failure===null?$n-count($first):null,
            'first_receipts_observed'=>$failure===null?count($first):null,'first_receipts_certified'=>$failure===null?count($certified):null,
            'partial_receipts_observed'=>$failure===null?$partial:null,'observed_quantity_coverage_orders'=>$failure===null?$covered:null,
            'observed_first_receipt'=>$this->statistics($first,$n,$options,$evaluatedAt,$timezone,false,$failure!==null),
            'verified_first_receipt'=>$this->statistics($certified,$n,$options,$evaluatedAt,$timezone,true,$failure!==null),
            'verified_complete_receipt'=>$this->statistics($complete,$n,$options,$evaluatedAt,$timezone,true,$failure!==null),
            'expected_to_first_difference'=>$this->statistics($deviations,$n,$options,$evaluatedAt,$timezone,false,$failure!==null),
            'quantity_fulfillment'=>null,'date_fulfillment'=>null,'commercial_closure'=>null,'confidence'=>null,'causes'=>$causes,'options'=>$options->audit(),
            'warnings'=>['La primera recepción observada no demuestra que no existieran recepciones omitidas.',
                'Cobertura observada de cantidades no equivale a cierre comercial ni pendiente confirmado.',
                'Órdenes sin vínculo no se clasifican automáticamente como incumplimientos.',
                'Muestra condicionada al corte: órdenes recientes pueden tener recepciones posteriores no observadas.']];
        return new SupplierLeadTimeData($productId,$supplierId,$locationId,$from,$until,$evaluatedAt,$timezone,$summary,$observations);
    }
    /**
     * Interpreta evidencia ya conciliada, conservando granularidad orden/producto y exclusiones.
     * @param PurchaseReconciliationData $row Resultado original, sin modificarlo.
     * @param DateTimeImmutable $from Inicio de ventana.
     * @param DateTimeImmutable $until Corte.
     * @param string $timezone Zona civil.
     * @param LeadTimeCoverageData|null $proof Cobertura previamente comprobada externamente.
     * @param string $key Identidad del alcance.
     * @return SupplierLeadTimeObservationData Fechas, diferencias y evidencia rastreable.
     */
    private function observation(PurchaseReconciliationData $row,DateTimeImmutable $from,DateTimeImmutable $until,string $timezone,?LeadTimeCoverageData $proof,string $key): SupplierLeadTimeObservationData
    {
        $order=$row->order;$first=$row->firstReceivedAt;$exclusions=[];$limits=$row->issues;$validLinks=[];$firstLink=null;$partialObserved=false;
        if($order->orderedAt===null){$exclusions[]='order_date_missing';}
        elseif($order->orderedAt<$from||$order->orderedAt>=$until){$exclusions[]='order_outside_interval';}
        if(!in_array($row->quality,['verified','partial'],true)){$exclusions[]='reconciliation_not_usable';}
        if($row->observedUntil!=$until){$exclusions[]='observation_cutoff_mismatch';}
        $digest=hash_init('sha256');
        foreach($row->links as $link){$receipt=$link->receipt;$movement=$receipt->movement;
            hash_update($digest,json_encode([$movement->id,$link->verified,$receipt->kind,$movement->occurredAt->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
            if(!$link->verified){$exclusions[]='rejected_documentary_link';continue;}
            if($movement->productId!==$row->productId||$movement->locationId!==$order->locationId||$receipt->supplierId!==$order->supplierId
                ||$receipt->orderDocument!==$order->document||$receipt->orderNumber!==$order->number){$exclusions[]='link_scope_mismatch';continue;}
            $validLinks[]=$movement->id; if($receipt->kind==='receipt'&&$movement->inventoryDelta!==null&&$row->orderedQuantity!==null&&BigDecimal::of($movement->inventoryDelta)->isPositive()&&BigDecimal::of($movement->inventoryDelta)->isLessThan($row->orderedQuantity)){$partialObserved=true;}
            if($receipt->kind==='receipt'&&$first!==null&&$movement->occurredAt==$first&&$movement->inventoryDelta!==null&&BigDecimal::of($movement->inventoryDelta)->isPositive()){$firstLink=$movement->id;}
        }
        if($first===null||$firstLink===null){$exclusions[]='no_verified_first_receipt';}
        elseif($order->orderedAt!==null&&($first<$order->orderedAt||$first>=$until)){$exclusions[]='incompatible_receipt_dates';}
        $days=$exclusions===[]?$this->days($order->orderedAt,$first,$timezone):null;
        $certified=$days!==null&&$proof!==null&&$proof->from<=$order->orderedAt&&$proof->until>=$row->observedUntil;
        if(!$certified){$limits[]='first_receipt_completeness_unverified';}
        $full=null;$fullAt=null;
        if($certified&&$proof->completeReceiptAt!==null&&$row->quality==='verified'&&$row->observedCompletionAt!==null
            &&$proof->completeReceiptAt==$row->observedCompletionAt&&$proof->completeReceiptAt>=$first&&$proof->completeReceiptAt<$until
            &&$row->orderedQuantity!==null&&BigDecimal::of($row->orderedQuantity)->isPositive()&&$row->netReceivedQuantity!==null
            &&BigDecimal::of($row->netReceivedQuantity)->isEqualTo($row->orderedQuantity)
            &&$row->returnedQuantity!==null&&BigDecimal::of($row->returnedQuantity)->isZero()
            &&$row->restoredQuantity!==null&&BigDecimal::of($row->restoredQuantity)->isZero()){
            foreach($row->links as $link){if($link->verified&&$link->receipt->kind==='receipt'&&$link->receipt->movement->occurredAt==$proof->completeReceiptAt&&$link->receipt->movement->inventoryDelta!==null&&BigDecimal::of($link->receipt->movement->inventoryDelta)->isPositive()){$fullAt=$proof->completeReceiptAt;$full=$this->days($order->orderedAt,$fullAt,$timezone);break;}}
        }
        if($full===null){$limits[]='complete_receipt_unverified';}
        $estimates=[];
        if($order->expectedAt!==null){$estimates[$order->expectedAt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d')]=$order->expectedAt;}
        foreach($order->lines as $line){if($line->productId===$row->productId&&$line->expectedAt!==null){$estimates[$line->expectedAt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d')]=$line->expectedAt;}}
        $estimate=count($estimates)===1?array_values($estimates)[0]:null;$difference=null;
        if($estimate!==null&&$days!==null&&$this->days($order->orderedAt,$estimate,$timezone)>=0){$difference=$this->days($estimate,$first,$timezone);}
        elseif(count($estimates)>1){$limits[]='expected_dates_conflict';}
        elseif($estimate!==null&&$order->orderedAt!==null&&$this->days($order->orderedAt,$estimate,$timezone)<0){$limits[]='expected_date_incompatible';}
        return new SupplierLeadTimeObservationData(['order_key'=>$key,'document'=>$order->document,'number'=>$order->number,'product_id'=>$row->productId,
            'supplier_id'=>$order->supplierId,'location_id'=>$order->locationId,'ordered_at'=>$order->orderedAt?->format(DATE_ATOM),
            'first_received_at'=>$first?->format(DATE_ATOM),'observed_completion_at'=>$row->observedCompletionAt?->format(DATE_ATOM),
            'complete_received_at'=>$fullAt?->format(DATE_ATOM),'expected_at'=>$estimate?->format(DATE_ATOM),'first_observed_days'=>$days,'complete_receipt_days'=>$full,
            'expected_to_first_days'=>$difference,'documentary_link_verified'=>$validLinks!==[]&&$exclusions===[],
            'first_receipt_certified'=>$certified,'partial_receipt_observed'=>$days!==null&&($partialObserved||$row->quality==='partial'||($row->observedCompletionAt!==null&&$first<$row->observedCompletionAt)),
            'observed_quantity_coverage'=>$days!==null&&$row->quality==='verified','commercial_closure'=>null,'reconciliation_quality'=>$row->quality,
            'first_receipt_identity'=>$firstLink,'verified_link_count'=>count($validLinks),'links_sha256'=>hash_final($digest),
            'coverage_reference'=>$certified?$proof->reference:null,'completion_reference'=>$full!==null?$proof->completionReference:null,
            'exclusions'=>array_values(array_unique($exclusions)),'limitations'=>array_values(array_unique($limits))]);
    }
    /**
     * Calcula días civiles, incluidos cruces de año y cero explícito de recepción el mismo día.
     * @param DateTimeImmutable $from Fecha inicial.
     * @param DateTimeImmutable $until Fecha final.
     * @param string $timezone Zona operativa.
     * @return int Diferencia firmada de días calendario.
     */
    private function days(DateTimeImmutable $from,DateTimeImmutable $until,string $timezone): int
    {
        $zone=new DateTimeZone($timezone);return (int)$from->setTimezone($zone)->setTime(0,0)->diff($until->setTimezone($zone)->setTime(0,0))->format('%r%a');
    }
    /**
     * Calcula estadísticas de población observada sin porcentajes de confianza ni certificados por muestra pequeña.
     * @param array $values Días y fechas de observaciones.
     * @param int $orders Órdenes del proveedor evaluadas.
     * @param SupplierLeadTimeOptions $options Criterios.
     * @param DateTimeImmutable $evaluatedAt Evaluación.
     * @param string $timezone Zona civil.
     * @param bool $certified Indica evidencia externa de completitud.
     * @param bool $failed El origen no terminó.
     * @return array Media, extremos, desviación poblacional y cobertura muestral.
     */
    private function statistics(array $values,int $orders,SupplierLeadTimeOptions $options,DateTimeImmutable $evaluatedAt,string $timezone,bool $certified,bool $failed): array
    {
        $n=count($values);$sum=BigDecimal::zero();$squares=BigDecimal::zero();$numbers=[];$latest=null;
        foreach($values as $value){$number=BigDecimal::of($value['days']);$sum=$sum->plus($number);$squares=$squares->plus($number->multipliedBy($number));$numbers[]=$value['days'];$date=new DateTimeImmutable($value['at']);if($latest===null||$date>$latest){$latest=$date;}}
        $mean=$n?(string)$sum->dividedBy($n,6,RoundingMode::HALF_UP):null;$deviation=null;
        if($n>1){$variance=$squares->multipliedBy($n)->minus($sum->multipliedBy($sum))->dividedBy($n*$n,18,RoundingMode::HALF_UP);$deviation=(string)$variance->sqrt(12)->toScale(6,RoundingMode::HALF_UP);}
        $coverage=$orders>0?(string)BigDecimal::of($n)->dividedBy($orders,6,RoundingMode::HALF_UP):null;
        $fresh=$latest!==null&&$latest<=$evaluatedAt&&$this->days($latest,$evaluatedAt,$timezone)<=$options->maximumAgeDays;
        $usable=$certified&&$n>=$options->minimumOrders&&$coverage!==null&&BigDecimal::of($n)->isGreaterThanOrEqualTo(BigDecimal::of($options->minimumCoverage)->multipliedBy($orders))&&$fresh;
        return ['observations'=>$failed?null:$n,'minimum_days'=>$n?(string)min($numbers):null,'average_days'=>$mean,'maximum_days'=>$n?(string)max($numbers):null,
            'deviation_days'=>$deviation,'coverage'=>$failed?null:$coverage,'latest_receipt_at'=>$latest?->format(DATE_ATOM),'fresh'=>$fresh,
            'quality'=>$failed||$n===0?'insufficient':($usable?'usable':'exploratory'),'certified_evidence'=>$certified&&$n>0&&!$failed,'confidence'=>null];
    }
}
