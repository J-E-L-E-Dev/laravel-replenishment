<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\InventoryParameterData;
use Brick\Math\{BigDecimal,BigRational,RoundingMode};
use DateTimeImmutable;
use InvalidArgumentException;

/** Calcula niveles de inventario a partir de los tramos de demanda ya resueltos en una generación. */
final class InventoryParameterCalculator
{
    /** @param ReplenishmentCalculator $calculator Lector de meses completos de pronóstico. */
    public function __construct(private ReplenishmentCalculator $calculator) {}

    /** Proyecta desde la fecha de análisis sin volver a consultar el historial ni aplicar dos veces la seguridad.
     * @param array $plan Evidencia congelada de la línea. @param InventoryParameterData|null $master Fotografía del maestro.
     * @return array Sugerencias, operandos exactos, calidad y advertencias. */
    public function compare(array $plan, ?InventoryParameterData $master): array
    {
        $before=$plan['demand_before_receipt'] ?? [];
        $after=$plan['demand_after_receipt'] ?? [];
        $coverage=$plan['policy']['target_coverage_days'] ?? null;
        $lead=isset($plan['lead_time']['lead_time_days'])
            ? BigDecimal::of($plan['lead_time']['lead_time_days'])->toScale(0,RoundingMode::CEILING)->toInt() : null;
        $protection=$coverage !== null && $lead !== null ? $lead+(int)$coverage : null;
        $fields=['minimum_days'=>$coverage,'maximum_days'=>$protection,'reorder_days'=>$lead];
        $horizons=['minimum'=>$coverage,'maximum'=>$protection,'reorder_point'=>$lead];
        $warnings=[];
        $compatible=$master !== null && $master->product===($plan['product_id'] ?? null)
            && $master->unit !== null && $master->unit===($plan['base_unit'] ?? null);
        if (!$compatible) {
            $warnings[]=$master===null?'El producto ya no figura en el maestro.':'La unidad del maestro y la evidencia no son compatibles.';
        }
        $safety=$plan['safety_units'] ?? null;
        if ($safety===null) { $warnings[]='La seguridad adicional es desconocida.'; }
        $suggested=$projected=[];
        foreach ($fields as $field=>$value) {
            $suggested[$field]=$compatible && $value!==null && $value>=0 && $value<=9999 ? (string)$value : null;
            if ($value!==null && ($value<0 || $value>9999)) { $warnings[]='Horizonte fuera del rango de destino.'; }
        }
        foreach ($horizons as $field=>$days) {
            $demand=$days===null || $days<0 || $days>9999 ? null : $this->projection((int)$days,$before,$after,$plan);
            $projected[$field]=$demand===null?null:(string)$demand->simplified();
            $suggested[$field]=null;
            if (!$compatible || $demand===null || $safety===null || $demand->isNegative() || BigDecimal::of($safety)->isNegative()) { continue; }
            $total=$demand->plus($safety)->simplified();
            if ($field!=='reorder_point' && $master->scale===null && !$total->getDenominator()->isEqualTo(1)) {
                $warnings[]='Falta definir la precisión de la unidad para sugerir una cantidad fraccionaria.';
                continue;
            }
            $scale=$field==='reorder_point'?0:($master->scale ?? 0);
            $rounded=(string)$total->toScale($scale,RoundingMode::CEILING);
            if ($this->valid($field,$rounded,$master->scale)) { $suggested[$field]=$rounded; }
            else { $warnings[]='La cantidad proyectada supera el rango del destino.'; }
        }
        $current=$master?->values ?? array_fill_keys(InventoryParameterData::FIELDS,null);
        foreach ($current as $value) {
            if ($value!==null && BigDecimal::of($value)->isNegative()) { $warnings[]='El maestro contiene un valor negativo heredado.'; break; }
        }
        $conflicts=$this->conflicts($suggested);
        $warnings=array_merge($warnings,$this->conflicts($current));
        if ($compatible && count(array_filter($projected,static fn($v)=>$v!==null))<3) {
            $warnings[]='La evidencia congelada no cubre todos los horizontes desde la fecha de análisis.';
        }
        $quality=$after['quality'] ?? 'insufficient';
        return ['product'=>$plan['product_id'] ?? null,'description'=>$plan['product_name'] ?? $master?->description,
            'unit'=>$plan['base_unit'] ?? null,'current_unit'=>$master?->unit,'scale'=>$master?->scale,
            'compatible'=>$compatible,'current'=>$current,'suggested'=>$suggested,'projected'=>$projected,
            'quality'=>$quality,'warnings'=>array_values(array_unique(array_merge($warnings,$conflicts))),
            'suggestion_conflicts'=>$conflicts,'evidence'=>[
                'as_of'=>$plan['as_of'] ?? null,'policy'=>$plan['policy'] ?? null,'lead_time'=>$plan['lead_time'] ?? null,
                'strategy'=>$after['method'] ?? null,'demand_evidence'=>$plan['demand_evidence'] ?? null,
                'before'=>$before,'after'=>$after,'safety_units'=>$safety,'available'=>$plan['availability']['available'] ?? null,
                'sales_run_id'=>$plan['demand_evidence']['window']['run_id'] ?? null,'algorithm'=>$plan['algorithm_version'] ?? null,
                'warnings'=>$plan['warnings'] ?? []]];
    }

    /** Recupera un prefijo exacto desde el análisis. Sólo admite tasas exactas o tramos completos acreditados.
     * @param int $days Horizonte civil. @param array $before Demanda del plazo. @param array $after Demanda posterior.
     * @param array $plan Resultado original. @return BigRational|null Consumo exacto o desconocido. */
    private function projection(int $days,array $before,array $after,array $plan): ?BigRational
    {
        if ($days===0) { return BigRational::zero(); }
        $qualities=['usable','exploratory','manual'];
        $first=isset($before['quantity'],$before['days']) && in_array($before['quality'] ?? null,$qualities,true);
        $second=isset($after['quantity'],$after['days']) && in_array($after['quality'] ?? null,$qualities,true);
        if ($first && $days===$before['days']) {
            return BigRational::of($before['quantity_exact'] ?? $before['quantity']);
        }
        if (!$first || !$second || $days>$before['days']+$after['days']) { return null; }
        if ($days===$before['days']+$after['days']) {
            return BigRational::of($before['quantity_exact'] ?? $before['quantity'])->plus($after['quantity_exact'] ?? $after['quantity']);
        }
        if (isset($after['rate_exact'],$after['quantity']) && ($before['rate_exact'] ?? null)===$after['rate_exact']) {
            return BigRational::of($after['rate_exact'])->multipliedBy($days);
        }
        if (($after['method'] ?? null)==='usable_monthly_forecast' && isset($plan['as_of'],$plan['base_unit'])) {
            $start=new DateTimeImmutable($plan['as_of']);
            $forecast=['base_unit'=>$plan['base_unit'],'source_reading_status'=>'complete',
                'forecasts'=>array_merge($before['months'] ?? [],$after['months'] ?? [])];
            $part=$this->calculator->demand($forecast,$start,$start->modify('+'.$days.' days'),$plan['base_unit']);
            if ($part['quality']==='usable') { return BigRational::of($part['quantity']); }
        }
        return null;
    }

    /** Valida magnitud, escala efectiva y capacidad del destino sin redondear la decisión humana.
     * @param string $field Parámetro. @param string $value Valor decidido. @param int|null $scale Precisión acreditada.
     * @return bool Si el valor es representable. */
    public function valid(string $field,string $value,?int $scale): bool
    {
        if (!preg_match('/^\d+(?:\.\d+)?$/D',$value)) { return false; }
        $quantity=in_array($field,['minimum','maximum'],true);
        $number=BigDecimal::of($value);
        $fraction=rtrim(explode('.',$value,2)[1] ?? '', '0');
        $digits=$quantity?16:($field==='reorder_point'?9:4);
        return strlen($fraction)<=($quantity?($scale ?? 0):0) && $number->isLessThan('1'.str_repeat('0',$digits));
    }

    /** Compara únicamente pares conocidos. @param array $values Valores finales. @return string[] Conflictos visibles. */
    private function conflicts(array $values): array
    {
        $messages=[];
        foreach ([['minimum','maximum','El mínimo supera el máximo.'],['reorder_point','maximum','El punto de pedido supera el máximo.'],
            ['minimum_days','maximum_days','Los días mínimos superan los máximos.']] as [$left,$right,$message]) {
            if (isset($values[$left],$values[$right]) && BigDecimal::of($values[$left])->isGreaterThan($values[$right])) { $messages[]=$message; }
        }
        return $messages;
    }

    /** Resuelve elecciones por campo y comprueba el conjunto antes de aprobar diferencias.
     * @param array $snapshot Fotografía revisada. @param array $choices Elecciones explícitas.
     * @return array Estado y decisiones auditables. @throws InvalidArgumentException Ante sugerencia ausente o decisión incompatible. */
    public function decide(array $snapshot,array $choices): array
    {
        $decisions=$values=[];
        $changed=false;
        foreach (InventoryParameterData::FIELDS as $field) {
            $mode=$choices[$field]['mode'] ?? null;
            $current=$snapshot['current'][$field];
            $value=match($mode) {
                'keep'=>$current,'suggested'=>$snapshot['suggested'][$field],'custom'=>$choices[$field]['value'] ?? null,
                default=>throw new InvalidArgumentException('Indique una decisión para cada campo.'),
            };
            if ($mode!=='keep' && (!$snapshot['compatible'] || !is_string($value) || !$this->valid($field,$value,$snapshot['scale']))) {
                throw new InvalidArgumentException('El valor elegido está ausente o es incompatible con la unidad, la precisión o el rango.');
            }
            $different=$current===null || $value===null ? $current!==$value : !BigDecimal::of($current)->isEqualTo($value);
            $changed=$changed || $different;
            $values[$field]=$value;
            $decisions[$field]=['mode'=>$mode,'value'=>$value,'changed'=>$different];
        }
        $issues=$this->conflicts($values);
        foreach ($values as $field=>$value) {
            if ($value!==null && !$this->valid($field,$value,$snapshot['scale'])) { $issues[]='El conjunto contiene valores incompatibles.'; break; }
        }
        if ($changed && $issues) { throw new InvalidArgumentException(implode(' ',$issues)); }
        $status=$changed?'approved':'unchanged';
        if (!$snapshot['compatible'] || $issues || (!$changed && !isset($snapshot['suggested']['minimum'],$snapshot['suggested']['maximum'],$snapshot['suggested']['reorder_point'])
            && !array_filter($values,static fn($v)=>$v!==null))) {
            $status='not_applicable';
        }
        return ['status'=>$status,'decisions'=>$decisions];
    }
}
