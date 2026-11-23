<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Asisnet\Replenishment\Contracts\PurchaseSource;
use Asisnet\Replenishment\DTO\PurchaseData;
use DateTimeImmutable;
use InvalidArgumentException;

/** Operaciones exactas de planificación sin reconstruir disponibilidad ni normalizar movimientos ERP. */
final class ReplenishmentCalculator
{
    /**
     * Lee el pendiente completo antes de publicar cantidades y separa su calendario.
     * @param PurchaseSource $source Contrato de pendientes certificados.
     * @param string $product Producto.
     * @param string $location Destino.
     * @param DateTimeImmutable $asOf Fecha de inventario.
     * @param DateTimeImmutable $target Objetivo de recepción.
     * @return array Estado, cantidades y evidencia; cualquier fallo invalida el lote.
     */
    public function incoming(PurchaseSource $source,string $product,string $location,DateTimeImmutable $asOf,DateTimeImmutable $target): array
    {
        $lines=[];$total=BigDecimal::zero();$eligible=BigDecimal::zero();$calendar=true;$seen=[];$scanned=0;
        try{
            foreach($source->pendingFor($product,$location) as $line){
                if(!$line instanceof PurchaseData||$line->id===''||$line->productId!==$product||$line->locationId!==$location||$line->supplierId===''||++$scanned>5000){throw new InvalidArgumentException('invalid_incoming_scope');}
                $quantity=BigDecimal::of($line->pendingQuantity);if(!$quantity->isPositive()){throw new InvalidArgumentException('invalid_pending_quantity');}
                $signature=[$line->supplierId,(string)$quantity->stripTrailingZeros(),$line->expectedAt?->format(DATE_ATOM)];
                if(isset($seen[$line->id])){if($seen[$line->id]!==$signature){throw new InvalidArgumentException('conflicting_pending_identity');}continue;}$seen[$line->id]=$signature;
                $date=$line->expectedAt?->setTimezone($asOf->getTimezone())->setTime(0,0);$total=$total->plus($quantity);
                if($date===null||$date<$asOf){$calendar=false;}elseif($date<=$target){$eligible=$eligible->plus($quantity);}
                $lines[]=['id'=>$line->id,'supplier_id'=>$line->supplierId,'quantity'=>(string)$quantity,'expected_at'=>$line->expectedAt?->format(DATE_ATOM)];
            }
            return ['status'=>$lines===[]?'certified_absent':'certified_positive','total'=>(string)$total,'by_target'=>$calendar?(string)$eligible:null,'calendar_known'=>$calendar,'lines'=>$lines,'issue'=>$calendar?null:'incoming_calendar_unknown'];
        }catch(\Throwable $error){return ['status'=>'unknown','total'=>null,'by_target'=>null,'calendar_known'=>false,'lines'=>[],'issue'=>'pending_not_certified'];}
    }
    /**
     * Redondea una necesidad positiva hacia arriba sin redondear antes el cociente.
     * @param string $required Necesidad base no negativa.
     * @param array $presentation Instantánea de factor, mínimo y múltiplo explícitos.
     * @return array Paquetes, unidades resultantes y excedente exactos.
     * @throws InvalidArgumentException Si faltan parámetros válidos.
     */
    public function packages(string $required,array $presentation): array
    {
        $need=BigDecimal::of($required);$factor=BigDecimal::of($presentation['base_units_per_package']);$minimum=BigDecimal::of($presentation['minimum_packages']);$multiple=BigDecimal::of($presentation['package_multiple']);
        if($need->isNegative()||!$factor->isPositive()||!$multiple->isPositive()||$minimum->isNegative()){throw new InvalidArgumentException('La presentación requiere factor y múltiplo positivos y mínimo no negativo.');}
        $count=$need->isZero()?BigDecimal::zero():$need->dividedBy($factor->multipliedBy($multiple),0,RoundingMode::CEILING)->multipliedBy($multiple);
        if(!$need->isZero()&&$count->isLessThan($minimum)){$count=$minimum->dividedBy($multiple,0,RoundingMode::CEILING)->multipliedBy($multiple);}
        $base=$count->multipliedBy($factor);return ['packages'=>(string)$count,'base_units'=>(string)$base,'rounding_excess'=>(string)$base->minus($need)];
    }
    /**
     * Suma exclusivamente meses completos comparables, sin interpolación diaria.
     * @param array|null $forecast Informe del motor existente.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param string $unit Unidad base verificada.
     * @return array Cantidad, calidad y meses usados; desconocido si hay huecos o insuficiencia.
     */
    public function demand(?array $forecast,DateTimeImmutable $from,DateTimeImmutable $until,string $unit): array
    {
        if($from==$until){return ['quantity'=>'0','quality'=>'usable','months'=>[]];}
        $unknown=['quantity'=>null,'quality'=>'insufficient','months'=>[]];
        if($from>$until||$from->format('d')!=='01'||$until->format('d')!=='01'||($forecast['base_unit']??null)!==$unit||($forecast['source_reading_status']??null)!=='complete'){return $unknown;}
        $map=[];foreach($forecast['forecasts']??[] as $row){if(isset($map[$row['period_start']])){return $unknown;}$map[$row['period_start']]=$row;}
        $total=BigDecimal::zero();$quality='usable';$months=[];
        for($date=$from;$date<$until;$date=$date->modify('+1 month')){
            $r=$map[$date->format('Y-m-d')]??null;
            if($r===null||($r['period_end']??null)!==$date->modify('+1 month')->format('Y-m-d')||!in_array($r['quality']??null,['usable','exploratory'],true)||($r['forecast_quantity']??null)===null){return $unknown;}
            $q=BigDecimal::of($r['forecast_quantity']);if($q->isNegative()){return $unknown;}$total=$total->plus($q);$months[]=$r;
            if($r['quality']==='exploratory'){$quality='exploratory';}
        }
        return ['quantity'=>(string)$total,'quality'=>$quality,'months'=>$months];
    }
    /**
     * Calcula el balance común sin duplicar consumo ni convertir desconocidos en cero.
     * @param string|null $available Disponibilidad normalizada, admite negativos.
     * @param string|null $before Demanda previa a recepción.
     * @param string|null $incoming Pendiente acreditado para la recepción o hipótesis explícita.
     * @param string|null $demand Demanda posterior hasta fin de protección.
     * @param string|null $safety Seguridad explícita o calibrada.
     * @return array Proyección, objetivo y necesidad exacta.
     */
    public function balance(?string $available, ?string $before, ?string $incoming, ?string $demand, ?string $safety): array
    {
        $projected = $available !== null && $before !== null && $incoming !== null ? (string) BigDecimal::of($available)->minus($before)->plus($incoming) : null;
        $target = $demand !== null && $safety !== null ? (string) BigDecimal::of($demand)->plus($safety) : null;
        $required = $projected !== null && $target !== null ? (string) BigDecimal::max('0', BigDecimal::of($target)->minus($projected)) : null;
        return compact('projected', 'target', 'required');
    }
}
