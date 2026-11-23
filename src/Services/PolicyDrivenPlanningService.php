<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\Models\{Policy,ProductSupplier,SupplierPresentation};
use Asisnet\Replenishment\DTO\ProductCostData;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;

/** Coordina la política elegida con motores existentes; no escribe ni aprueba compras. */
final class PolicyDrivenPlanningService
{
    public const VERSION = 'policy-planning-2.0';

    /** Recibe servicios existentes y diagnósticos puros. @param AdapterRegistry $adapters Fuentes. @param AvailabilityQuery $availability Disponibilidad normalizada. @param PlanningHistory $history Serie materializada y evidencia del intervalo. @param SupplierLeadTimeQuery $lead Plazos. @param ForecastQuery $forecast Pronóstico. @param PolicyDemandResolver $demand Resolución temporal. @param DemandTrendAnalyzer $trend Tendencia. @param ExtendedCoverageEvaluator $coverage Cobertura. @param ReplenishmentCalculator $calculator Balance y redondeo comunes. @param ReplenishmentQuery $query Presentaciones. */
    public function __construct(private AdapterRegistry $adapters, private AvailabilityQuery $availability, private PlanningHistory $history,
        private SupplierLeadTimeQuery $lead, private ForecastQuery $forecast, private PolicyDemandResolver $demand, private DemandTrendAnalyzer $trend,
        private ExtendedCoverageEvaluator $coverage, private ReplenishmentCalculator $calculator, private ReplenishmentQuery $query) {}

    /**
     * Calcula un producto con ventana histórica efectiva y protección igual a plazo más cobertura.
     * @param ProductSupplier $relation Relación activa seleccionada.
     * @param Policy $policy Política activa elegida expresamente.
     * @param string $location Destino del consumidor.
     * @param DateTimeImmutable $asOf Día operativo actual, nunca fecha histórica con reservas actuales.
     * @param array $options Hipótesis explícitas: allow_heuristic, manual_daily, safety_units, assume_no_incoming, extended_days, conservative_daily y presentation_id.
     * @param string $actor Actor autenticado suministrado por el consumidor.
     * @param string|null $legacyFingerprint Huella diaria preparada para consumidores con SaleSource.
     * @param int|null $salesRunId Publicación comercial congelada para demanda, tendencia y pronóstico.
     * @return array Evidencia, estado operativo, matemáticas y advertencias; no es una propuesta aprobada.
     * @throws InvalidArgumentException Si identidad, política, fecha o parámetros no son válidos.
     * @throws \Throwable Si una fuente indispensable no está disponible; el coordinador por lotes registra error individual.
     */
    public function analyze(ProductSupplier $relation, Policy $policy, string $location, DateTimeImmutable $asOf, array $options, string $actor, ?int $salesRunId = null, ?string $legacyFingerprint = null): array
    {
        $timezone = config('app.timezone', 'UTC'); $asOf=$asOf->setTimezone(new \DateTimeZone($timezone)); $today = CarbonImmutable::now($timezone)->startOfDay();
        if (!$relation->active || !$policy->active || $actor === '' || $asOf != $today || $asOf->getTimezone()->getName() !== $timezone || $policy->analysis_days < 1 || $policy->analysis_days > 3650 || $policy->target_coverage_days < 1 || $policy->target_coverage_days > 730) { throw new InvalidArgumentException('Seleccione relación y política activas, una ventana acotada y la fecha operativa actual.'); }
        foreach (['safety_units', 'manual_daily', 'conservative_daily'] as $field) { if (isset($options[$field]) && (!is_string($options[$field]) || !preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D', $options[$field]))) { throw new InvalidArgumentException('Las cantidades deben ser cadenas decimales no negativas.'); } }
        $product = $this->adapters->resolve('products')->find($relation->product_id);
        if ($product === null || $product->id !== $relation->product_id || $product->stockUnit === '') { throw new InvalidArgumentException('El producto no tiene unidad base verificada.'); }
        $available = $this->availability->forProduct($product->id, $location);
        $from = $asOf->modify('-'.$policy->analysis_days.' days');
        $settings = PolicyDemandSettings::normalize($policy->demand_settings ?? []);
        [$history, $window] = $this->history->read($product, $location, $from, $asOf, $settings, $policy->continuity_authorized_by, $salesRunId, $legacyFingerprint);
        if ($salesRunId > 0 && $window['run_id'] !== $salesRunId) {
            throw new GenerationFailure('profile_incompatible','El perfil comercial vigente es incompatible con la publicación fijada.',true);
        }
        $runId = $window['run_id'];
        $trend = $this->trend->analyze($history, signal: $settings['signal']);
        $lead = $this->lead->analyze($product->id, $relation->supplier_id, $location, $from, $asOf, [], $policy)->resolution;
        $leadDays = $lead['lead_time_days'] === null ? null : BigDecimal::of($lead['lead_time_days'])->toScale(0, RoundingMode::CEILING)->toInt();
        if ($leadDays !== null && ($leadDays < 0 || $leadDays > 730)) { throw new InvalidArgumentException('El plazo excede el límite de dos años del planificador.'); }
        $receipt = $leadDays === null ? null : $asOf->modify('+'.$leadDays.' days');
        $end = $receipt?->modify('+'.$policy->target_coverage_days.' days');
        $forecast = null; $warnings = $lead['warnings'] ?? [];
        if ($settings['prefer_forecast'] && $settings['signal'] === 'verified_gross_sales' && $window['status'] !== 'refresh_required' && $receipt !== null && $asOf->format('d') === '01' && $receipt->format('d') === '01' && $end->format('d') === '01') {
            $months = ((int) $end->format('Y') - (int) $asOf->format('Y')) * 12 + (int) $end->format('m') - (int) $asOf->format('m');
            if ($months >= 1 && $months <= 6) { $training = $history->from->format('d') === '01' ? $history->from : $history->from->modify('first day of next month'); if ($training < $asOf) { try{$forecast = $this->forecast->analyze($product->id, $location, $training, $asOf, $months, true, true, $policy->seasonal_minimum_years, $product, $runId)->audit();}catch(\Throwable $error){$warnings[]='No se obtuvo un pronóstico utilizable con los parámetros seleccionados. Sólo se consideran alternativas expresamente autorizadas.';} } }
        }
        $unknown = ['quantity' => null, 'rate' => null, 'quality' => 'insufficient', 'method' => 'insufficient'];
        $demand = $this->demand->protection($history, $policy->toArray(), $product->stockUnit, $asOf, $receipt ?? $asOf,
            $end ?? $asOf, $forecast, ($options['allow_heuristic'] ?? false) === true, $options['manual_daily'] ?? null);
        if ($window['status'] === 'outside_cutoff') {
            $demand['evidence']['limitation'] = 'La ventana solicitada no contiene días posteriores al corte estadístico.';
        }
        $before = $receipt === null ? $unknown : $demand['before'];
        $after = $receipt === null ? $unknown : $demand['after'];
        if ($policy->demand_settings === null && !$history->statistics) {
            foreach ([&$before, &$after] as &$part) {
                if ($part['method'] === 'historical_average') {
                    $part['method'] = 'historical_daily_rate_heuristic';
                }
            }
            unset($part);
        }
        if ($demand['evidence']['limitation'] !== null) {
            $warnings[] = $demand['evidence']['limitation'];
        }
        if ($window['assumed_days'] > 0) {
            $warnings[] = 'Se asumió continuidad de unidad por autorización expresa. La demanda estimada es exploratoria.';
        }
        $warnings[] = 'Las ventas observadas pueden estar restringidas por inventario. La demanda perdida sigue desconocida.';
        $incoming = $this->calculator->incoming($this->adapters->resolve('purchases'), $product->id, $location, $asOf, $receipt ?? $asOf);
        $incomingUsed = $incoming['by_target']; $assumptions = [];
        if ($incoming['status'] === 'unknown' && ($options['assume_no_incoming'] ?? false) === true) { $incomingUsed = '0'; $assumptions['assume_no_incoming'] = true; }
        if (isset($options['safety_units'])) { $assumptions['manual_safety_units'] = $options['safety_units']; }
        foreach (['before' => $before, 'after' => $after] as $key => $resolved) { if (in_array($resolved['quality'], ['manual', 'exploratory'], true)) { $assumptions[$key.'_demand'] = $resolved; } }
        $stock = $available?->observedAt?->setTimezone($asOf->getTimezone())->format('Y-m-d') === $asOf->format('Y-m-d') ? $available->available : null;
        $balance = $this->calculator->balance($stock, $before['quantity'], $incomingUsed, $after['quantity'], $options['safety_units'] ?? null);
        $presentation = null;
        $candidates = SupplierPresentation::where('product_supplier_id', $relation->id)->where('active', true)->get();
        foreach ($candidates as $candidate) { $snapshot = $this->query->presentation($candidate); if ($snapshot['active'] && $snapshot['current_revision'] && (!isset($options['presentation_id']) || $candidate->id === (int) $options['presentation_id'])) { if ($presentation !== null) { $presentation = null; break; } $presentation = $snapshot; } }
        $packages = $presentation !== null && $balance['required'] !== null ? $this->calculator->packages($balance['required'], $presentation) : ['packages' => null, 'base_units' => null, 'rounding_excess' => null];
        $protectionDays=$leadDays===null?null:$leadDays+$policy->target_coverage_days;
        $manualHorizon=isset($options['extended_days'])&&$options['extended_days']!=='';
        if($manualHorizon&&(!preg_match('/^[0-9]+$/D',(string)$options['extended_days'])||(int)$options['extended_days']<1||(int)$options['extended_days']>3650)){throw new InvalidArgumentException('Indique un horizonte manual entero entre uno y 3650 días.');}
        $extendedDays=$manualHorizon?(int)$options['extended_days']:$protectionDays;
        $extendedEnd=$extendedDays===null?null:$asOf->modify('+'.$extendedDays.' days');
        // Se reutilizan ambos consumos, nunca la tasa redondeada ni el saldo ya reducido por el plazo.
        $centralDemand=$before['quantity']!==null&&$after['quantity']!==null?(string)BigDecimal::of($before['quantity'])->plus($after['quantity']):null;
        $demandSource='accumulated_protection';
        if($extendedDays!==$protectionDays){
            $centralDemand=null;$demandSource='unknown_manual_horizon';
            if($protectionDays!==null&&$extendedEnd!==null){
                $resolved=$this->calculator->demand($forecast,$asOf,$extendedEnd,$product->stockUnit);
                if($after['method']==='usable_monthly_forecast'&&$resolved['quality']==='usable'&&$extendedDays<=$policy->forecast_horizon_days){$centralDemand=$resolved['quantity'];$demandSource='compatible_monthly_forecast';}
                if($after['method']==='manual_daily_rate'&&isset($options['manual_daily'])){$centralDemand=(string)BigDecimal::of($options['manual_daily'])->multipliedBy($extendedDays);$demandSource='explicit_manual_daily';}
            }
        }
        $scenario = $settings['conservative'];
        if ($policy->demand_settings === null && isset($options['conservative_daily'])) {
            $scenario = 'manual';
        }
        $conservativeRate = match ($scenario) {
            'max_general_recent' => $demand['evidence']['conservative_rate'],
            'manual' => $options['conservative_daily'] ?? null,
            default => null,
        };
        $conservativeDemand = $extendedDays !== null && $conservativeRate !== null
            && ($extendedDays === $protectionDays || $scenario === 'manual')
            ? (string)BigRational::of($conservativeRate)->multipliedBy($extendedDays)->toScale(12,RoundingMode::HALF_UP) : null;
        $lowerScenario = $conservativeDemand !== null && $centralDemand !== null && BigDecimal::of($conservativeDemand)->isLessThan($centralDemand);
        $coverageIncoming=$extendedEnd===null?null:$this->coverage->incomingWithin($incoming,$asOf,$extendedEnd);
        $extended=$this->coverage->evaluateAccumulated($stock,$centralDemand,$extendedDays,$options['safety_units']??null,$coverageIncoming,$lowerScenario ? null : $conservativeDemand,$scenario !== 'none',($incoming['status']??'unknown')==='unknown'&&($options['assume_no_incoming']??false)===true);
        $extended['conservative_source'] = $scenario === 'none' ? null : ($scenario === 'manual' ? 'explicit_manual_scenario' : $scenario);
        $extended['conservative_rate_exact'] = $conservativeRate;
        $extended['conservative_quality'] = $conservativeDemand === null || $lowerScenario ? 'insufficient' : ($scenario === 'manual' ? 'manual' : 'exploratory');
        if ($lowerScenario) {
            $extended['classification'] = 'scenario_conflict';
            $extended['warning'] = 'Revisión necesaria';
            $extended['warnings'][] = 'El escenario conservador propuesto queda por debajo del central. Revise su tasa.';
            $extended['rejected_conservative_demand'] = $conservativeDemand;
        }
        if (isset($after['rate_exact']) && BigRational::of($after['rate_exact'])->isZero()) {
            $extended['requires_commercial_review'] = true;
            $extended['candidate'] = false;
            $extended['warning'] = 'Revisión necesaria';
            $warning = 'La tasa cero exige revisión comercial. Las ventas observadas no acreditan demanda futura nula.';
            $extended['warnings'][] = $warning;
            $warnings[] = $warning;
        }
        $extended+=['horizon_source'=>$manualHorizon?'explicit_manual':'automatic_protection','automatic_days'=>$protectionDays,'demand_source'=>$demandSource,'until_exclusive'=>$extendedEnd?->format('Y-m-d'),'demand_quality'=>['before'=>$before['quality'],'after'=>$after['quality']]];
        $status = $presentation === null ? 'configuration_required' : ($balance['required'] === null || $leadDays === null ? 'insufficient_data' : (BigDecimal::of($balance['required'])->isPositive() ? 'purchase_needed' : 'no_purchase_needed'));
        if ($status === 'no_purchase_needed' && $extended['candidate']) { $status = 'extended_coverage_candidate'; }
        if ($status === 'purchase_needed' && $trend['classification'] === 'declining') { $status = 'declining_demand_review'; }
        $cost = null;
        if (config('replenishment.adapters.product_costs') !== null && $presentation !== null && $packages['packages'] !== null) {
            try { $value = $this->adapters->resolve('product_costs')->forProduct($product->id); if ($value->productId !== $product->id) { throw new InvalidArgumentException('El costo pertenece a otro producto.'); } $cost = $value->totals($product->stockUnit, $presentation['base_units_per_package'], $packages['packages']); }
            catch (\Throwable $error) { $warnings[] = 'No se pudo verificar el costo; se conserva desconocido sin bloquear el diagnóstico.'; }
        }
        return ['algorithm_version' => self::VERSION, 'product_id' => $product->id, 'product_name' => $product->name, 'supplier_id' => $relation->supplier_id, 'location_id' => $location, 'base_unit' => $product->stockUnit,
            'status' => $status, 'as_of' => $asOf->format(DATE_ATOM), 'actor_id' => $actor, 'policy' => $policy->toArray(), 'options' => $options,
            'history' => $history->audit(), 'demand_evidence' => $demand['evidence'] + ['window'=>$window], 'trend' => $trend, 'lead_time' => $lead, 'protection_days' => $leadDays === null ? null : $leadDays + $policy->target_coverage_days,
            'protection_demand' => $before['quantity']!==null&&$after['quantity']!==null?(string)BigDecimal::of($before['quantity'])->plus($after['quantity']):null, 'projected_stock_at_end_without_purchase' => $balance['projected']!==null&&$after['quantity']!==null?(string)BigDecimal::of($balance['projected'])->minus($after['quantity']):null, 'projected_stock_at_end_with_suggested_purchase' => $balance['projected']!==null&&$after['quantity']!==null&&$packages['base_units']!==null?(string)BigDecimal::of($balance['projected'])->minus($after['quantity'])->plus($packages['base_units']):null,
            'demand_before_receipt' => $before, 'demand_after_receipt' => $after, 'availability' => $available === null ? null : array_replace(get_object_vars($available), ['observedAt'=>$available->observedAt?->format(DATE_ATOM)]),
            'incoming' => $incoming, 'incoming_used' => $incomingUsed, 'safety_units' => $options['safety_units'] ?? null, 'balance' => $balance, 'presentation' => $presentation, 'packages' => $packages,
            'extended_coverage' => $extended, 'cost' => $cost, 'assumptions' => $assumptions, 'warnings' => array_merge($warnings, ['El nivel de servicio es informativo: no calcula seguridad.', 'La política no convierte una heurística exploratoria en pronóstico certificado.']),
            'formulas' => ['projected' => 'disponible - demanda durante plazo + pendiente acreditado antes de recepción', 'target' => 'demanda durante cobertura + seguridad explícita', 'required' => 'máximo(0, objetivo - proyectado)', 'packages' => 'redondeo por factor, mínimo y múltiplo existentes']];
    }
}
