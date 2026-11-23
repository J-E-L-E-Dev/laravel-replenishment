<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Contrasta balances acumulados; no certifica continuidad de existencias ni excluye productos. */
final class ExtendedCoverageEvaluator
{
    /**
     * Conserva la firma anterior para integraciones existentes sin inventar seguridad ni pendientes.
     * @param string|null $available Disponibilidad normalizada.
     * @param string|null $dailyRate Tasa central declarada por el llamante anterior.
     * @param int $extendedDays Horizonte manual.
     * @param string|null $conservativeRate Tasa conservadora manual opcional.
     * @return array Diagnóstico insuficiente: la firma anterior no acredita seguridad ni entradas.
     * @throws InvalidArgumentException Si días o tasas son inválidos.
     */
    public function evaluate(?string $available, ?string $dailyRate, int $extendedDays, ?string $conservativeRate = null): array
    {
        foreach ([$dailyRate,$conservativeRate] as $rate) { if ($rate!==null&&BigDecimal::of($rate)->isNegative()) { throw new InvalidArgumentException('Las tasas no pueden ser negativas.'); } }
        return $this->evaluateAccumulated($available,$dailyRate===null?null:(string)BigDecimal::of($dailyRate)->multipliedBy($extendedDays),$extendedDays,null,null,
            $conservativeRate===null?null:(string)BigDecimal::of($conservativeRate)->multipliedBy($extendedDays),$conservativeRate!==null);
    }

    /**
     * Evalúa entradas certificadas o cero expresamente supuesto, sin alterar la certificación original.
     * @param string|null $available Disponibilidad normalizada, nunca el saldo ya reducido por consumo.
     * @param string|null $centralDemand Demanda acumulada del horizonte exacto, incluida la del plazo.
     * @param int|null $days Horizonte civil; NULL cuando falta el plazo automático.
     * @param string|null $safety Seguridad explícita; NULL no significa cero.
     * @param string|null $certifiedIncoming Entradas con calendario verificado dentro del horizonte; ausencia certificada es cero.
     * @param string|null $conservativeDemand Demanda acumulada del escenario conservador explícito.
     * @param bool $hasConservative Permite declarar un escenario solicitado pero desconocido; una cantidad conservadora siempre se considera.
     * @param bool $assumeZeroIncoming Autoriza cero sólo si la cantidad certificada es desconocida; el llamante no debe aplicarlo a entradas positivas con calendario inválido.
     * @return array Suficiencia por escenario, discrepancias y desconocidos; no modifica calidad ni históricos.
     * @throws InvalidArgumentException Si el horizonte o las magnitudes de demanda, seguridad o entradas son negativos.
     */
    public function evaluateAccumulated(?string $available,?string $centralDemand,?int $days,?string $safety,?string $certifiedIncoming,?string $conservativeDemand=null,bool $hasConservative=false,bool $assumeZeroIncoming=false): array
    {
        if($days!==null&&($days<1||$days>3650)){throw new InvalidArgumentException('La cobertura debe contener entre uno y 3650 días.');}
        foreach([$centralDemand,$safety,$certifiedIncoming,$conservativeDemand] as $value){if($value!==null&&BigDecimal::of($value)->isNegative()){throw new InvalidArgumentException('Demanda, seguridad y entradas no pueden ser negativas.');}}
        $hasConservative=$hasConservative||$conservativeDemand!==null;
        $assumed=$certifiedIncoming===null&&$assumeZeroIncoming;
        $considered=$certifiedIncoming??($assumed?'0':null);
        $source=$certifiedIncoming!==null?'certified_incoming':($assumed?'explicit_zero_incoming_assumption':'unknown');
        $position=$days!==null&&$available!==null&&$safety!==null&&$considered!==null?BigDecimal::of($available)->plus($considered)->minus($safety):null;
        $central=$position!==null&&$centralDemand!==null?$position->minus($centralDemand):null;
        $conservative=$hasConservative&&$position!==null&&$conservativeDemand!==null?$position->minus($conservativeDemand):null;
        $centralEnough=$central===null?null:!$central->isNegative();$conservativeEnough=$conservative===null?null:!$conservative->isNegative();
        $complete=$centralEnough!==null&&(!$hasConservative||$conservativeEnough!==null);
        $conflict=$complete&&$hasConservative&&$centralEnough!==$conservativeEnough;
        $candidate=$complete&&$centralEnough&&(!$hasConservative||$conservativeEnough);
        $classification=!$complete?'insufficient_data':($conflict?'scenario_conflict':($candidate?($hasConservative?'both_sufficient':'central_sufficient'):'insufficient_coverage'));
        return ['assessment_version'=>'coverage-2.1','days'=>$days,'central_demand'=>$centralDemand,'conservative_demand'=>$conservativeDemand,
            'incoming_considered'=>$considered,'incoming_source'=>$source,'incoming_assumption'=>$assumed?'explicit_zero_incoming_assumption':null,
            'diagnostic_quality'=>$complete?'exploratory':'insufficient','warnings'=>$assumed?['Se simulan cero entradas por autorización expresa; no se certifica ausencia de compras pendientes.','Diagnóstico exploratorio: no garantiza ausencia de agotamientos.']:['El balance es exploratorio y conserva las hipótesis de demanda y seguridad.'],
            'conservative_source'=>$hasConservative?'explicit_manual_scenario':null,'safety_units'=>$safety,'certified_incoming'=>$certifiedIncoming,
            'central_balance'=>$central===null?null:(string)$central,'conservative_balance'=>$conservative===null?null:(string)$conservative,
            'central_sufficient'=>$centralEnough,'conservative_sufficient'=>$conservativeEnough,'classification'=>$classification,
            'coverage_days'=>null,'candidate'=>$candidate,'excluded'=>false,'confidence'=>null,
            'warning'=>$conflict?'Revisión necesaria':($candidate?($assumed?'Posible sobrecobertura exploratoria con hipótesis de cero entradas. No garantiza ausencia de agotamientos.':'Posible sobrecobertura: el balance acumulado podría cubrir el horizonte evaluado. Considere excluir este producto.'):(!$complete?'Información insuficiente para evaluar cobertura extendida.':null)),
            'limitations'=>['El balance acumulado no garantiza ausencia de agotamientos dentro del horizonte.','La calidad original de la demanda y sus hipótesis se conserva; no se certifica un pronóstico exploratorio.']];
    }

    /**
     * Cuenta entradas acreditadas por fecha sin releer fuentes ni sumar total y líneas a la vez.
     * @param array $incoming Lote normalizado completo de ReplenishmentCalculator, sin sustituir desconocidos por hipótesis.
     * @param \DateTimeImmutable $from Inicio inclusivo de evaluación.
     * @param \DateTimeImmutable $until Fin exclusivo de protección.
     * @return string|null Total exacto; NULL si falta certificación o calendario aplicable.
     */
    public function incomingWithin(array $incoming,\DateTimeImmutable $from,\DateTimeImmutable $until): ?string
    {
        if($until<=$from||!in_array($incoming['status']??null,['certified_absent','certified_positive'],true)||($incoming['calendar_known']??false)!==true){return null;}
        $sum=BigDecimal::zero();$all=BigDecimal::zero();$seen=[];
        foreach($incoming['lines']??[] as $line){
            if(empty($line['expected_at'])||!isset($line['id'],$line['quantity'])||isset($seen[$line['id']])){return null;}$seen[$line['id']]=true;
            try{$at=(new \DateTimeImmutable($line['expected_at']))->setTimezone($from->getTimezone())->setTime(0,0);$quantity=BigDecimal::of($line['quantity']);}catch(\Throwable $error){return null;}
            if($at<$from||!$quantity->isPositive()){return null;}$all=$all->plus($quantity);if($at<$until){$sum=$sum->plus($quantity);}
        }
        if(($incoming['total']??null)===null||!$all->isEqualTo($incoming['total'])||(($incoming['status']==='certified_absent')!==$all->isZero())){return null;}
        return (string)$sum;
    }
}
