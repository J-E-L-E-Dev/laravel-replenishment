<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\ObservedDemandData;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use InvalidArgumentException;

/** Selecciona una estrategia común para todo el horizonte y conserva sus operandos exactos. */
final class PolicyDemandResolver
{
    /** @param ReplenishmentCalculator $calculator Suma de meses completos certificados. */
    public function __construct(private ReplenishmentCalculator $calculator) {}

    /** Resuelve un tramo solicitado por los consumidores anteriores.
     * @param ObservedDemandData $history Historia. @param array $policy Política activa. @param string $unit Unidad.
     * @param DateTimeImmutable $from Inicio. @param DateTimeImmutable $until Fin exclusivo.
     * @param array|null $forecast Pronóstico. @param bool $allowHeuristic Autorización. @param string|null $manualDaily Tasa individual.
     * @return array Cantidad, procedencia y exclusiones. @throws InvalidArgumentException Ante parámetros inválidos. */
    public function resolve(ObservedDemandData $history, array $policy, string $unit, DateTimeImmutable $from, DateTimeImmutable $until,
        ?array $forecast = null, bool $allowHeuristic = false, ?string $manualDaily = null): array
    {
        $plan = $this->protection($history, $policy, $unit, $from, $from, $until, $forecast, $allowHeuristic, $manualDaily);
        $result = $plan['after'];
        if ($result['method'] === 'historical_average' && !isset($policy['demand_settings'])) {
            $result['method'] = 'historical_daily_rate_heuristic';
        }
        if ($from == $until) {
            $result = $this->interval($from,$until);
        }
        return $result + ['analysis_from'=>$history->from->format('Y-m-d'), 'analysis_until'=>$history->until->format('Y-m-d'),
            'observed_days'=>$history->summary['observed_days'], 'gross_sales_units'=>$history->summary['gross_sales_units']];
    }

    /** Evalúa la elegibilidad del historial, compara estrategias y usa una sola fuente para ambos tramos.
     * @param ObservedDemandData $history Serie con continuidad y segmento ya delimitados.
     * @param array $policy Política congelada. @param string $unit Unidad comercial comprobada.
     * @param DateTimeImmutable $from Comienzo del plazo. @param DateTimeImmutable $receipt Recepción orientativa.
     * @param DateTimeImmutable $until Fin de protección. @param array|null $forecast Pronóstico de la misma ejecución estricta.
     * @param bool $authorized Permiso de usar promedios exploratorios. @param string|null $manual Tasa individual.
     * @return array Ambos tramos, escenario conservador histórico y evidencia común.
     * @throws InvalidArgumentException Ante fechas, política o tasa inválidas. */
    public function protection(ObservedDemandData $history, array $policy, string $unit, DateTimeImmutable $from,
        DateTimeImmutable $receipt, DateTimeImmutable $until, ?array $forecast, bool $authorized, ?string $manual): array
    {
        $settings = PolicyDemandSettings::normalize($policy['demand_settings'] ?? []);
        if (empty($policy['active']) || $unit === '' || $from < $history->until || $receipt < $from || $until < $receipt
            || $until > $from->modify('+3650 days') || $history->summary['calendar_days'] > $policy['analysis_days']
            || $policy['minimum_history_days'] < 1 || $policy['forecast_horizon_days'] < 1
            || $manual !== null && !preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D', $manual)) {
            throw new InvalidArgumentException('Verifique política, intervalo de protección, unidad y tasa individual.');
        }
        foreach ([$from, $receipt, $until] as $date) {
            if ($date->format('H:i:s.u') !== '00:00:00.000000' || $date->getTimezone()->getName() !== $history->timezone) {
                throw new InvalidArgumentException('Use días civiles completos en la zona operativa.');
            }
        }
        $reason = null;
        $units = array_unique(array_filter(array_column($history->days, 'base_unit'), fn ($value) => $value !== null));
        $requestedStart = $history->until->modify('-'.$policy['analysis_days'].' days');
        $window = $history->statistics['planning_window'] ?? [];
        $justified = $history->from == $requestedStart || (isset($history->statistics['run_id'])
            && ($window['requested_start'] ?? null) === $requestedStart->format('Y-m-d')
            && ($window['segment_start'] ?? null) === $history->from->format('Y-m-d')
            && ($window['until'] ?? null) === $history->until->format('Y-m-d'));
        if (!$justified || ($history->statistics['status'] ?? null) === 'refresh_required') {
            $reason = 'Actualización necesaria: falta una ejecución comercial compatible para la ventana.';
        } elseif ($history->summary['observation_status'] !== 'complete' || $history->summary['observed_days'] < $policy['minimum_history_days']
            || array_values($units) !== [$unit]) {
            $reason = 'La ventana comparable no acredita unidad, cobertura completa o mínimo histórico suficiente.';
        } elseif (!BigDecimal::of($history->summary['gross_sales_units'])->isPositive()) {
            $reason = 'Sin ventas brutas en la ventana. La demanda futura permanece desconocida.';
        }
        $field = $settings['signal'] === 'verified_net_sales' ? 'net_sales_units' : 'gross_sales_units';
        $blocks = [];
        $rate = $conservative = null;
        $adequate = $reason === null;
        $canEstimate = $adequate && $authorized;
        if ($canEstimate) {
            $general = $this->block($history, $field, 0, count($history->days));
            $recent = $settings['recent_days'] === null ? null : $this->block($history, $field, 0, $settings['recent_days']);
            if ($settings['conservative'] === 'max_general_recent' && isset($general['rate'], $recent['rate'])) {
                $conservative = BigRational::max($general['rate'], $recent['rate'])->simplified();
            }
            if ($settings['strategy'] === 'weighted_average') {
                $rate = BigRational::zero();
                $offset = 0;
                foreach ($settings['blocks'] as $spec) {
                    $block = $this->block($history, $field, $offset, $spec['days']);
                    $blocks[] = $spec + $block;
                    $offset += $spec['days'];
                    if (!isset($block['rate'])) {
                        $rate = null;
                    } elseif ($rate !== null) {
                        $rate = $rate->plus(BigRational::of($block['rate'])->multipliedBy($spec['weight'])->dividedBy(100));
                    }
                }
            } else {
                $block = $settings['strategy'] === 'recent_average' ? $recent : $general;
                $blocks[] = $block;
                $rate = isset($block['rate']) ? BigRational::of($block['rate']) : null;
            }
            if (!in_array(true, array_column($blocks, 'has_gross_sales'), true)) {
                $rate = null;
            }
            if ($rate === null) {
                $reason = 'La señal es negativa, faltan ventas o la ventana no cubre todos los bloques seleccionados.';
            }
        }
        $evidence = ['settings'=>$settings, 'blocks'=>$blocks, 'general'=>$general ?? null, 'recent'=>$recent ?? null,
            'minimum_history_days'=>$policy['minimum_history_days'], 'history_authorized'=>$authorized,
            'conservative_rate'=>$conservative === null ? null : (string)$conservative, 'limitation'=>$reason];
        $first = $this->interval($from, $receipt);
        $second = $this->interval($receipt, $until);
        $source = $forecast['source_statistics'] ?? [];
        $compatible = $forecast && $settings['prefer_forecast'] && $settings['signal'] === 'verified_gross_sales'
            && ($forecast['signal'] ?? null) === $settings['signal'] && ($forecast['product_id'] ?? null) === $history->productId
            && ($forecast['location_id'] ?? null) === $history->locationId && ($forecast['base_unit'] ?? null) === $unit
            && isset($forecast['cutoff']) && new DateTimeImmutable($forecast['cutoff']) == $history->until
            && $until <= $history->until->modify('+'.$policy['forecast_horizon_days'].' days')
            && (!$history->statistics || (isset($source['run_id'], $source['profile_hash'])
                && $source['run_id'] === ($history->statistics['run_id'] ?? null)
                && $source['profile_hash'] === $history->statistics['profile_hash']
                && ($forecast['source_algorithm_version'] ?? null) === $history->version
                && ($forecast['algorithm_version'] ?? null) === DemandForecastEngine::VERSION));
        if ($compatible && $adequate) {
            $parts = [];
            foreach ([[$from,$receipt,$first], [$receipt,$until,$second]] as [$begin,$end,$part]) {
                $value = $end == $begin ? ['quality'=>'usable','quantity'=>'0','months'=>[]] : $this->calculator->demand($forecast,$begin,$end,$unit);
                if ($value['quality'] !== 'usable' || $value['quantity'] === null) {
                    break;
                }
                $parts[] = array_replace($part, ['method'=>'usable_monthly_forecast','quality'=>'usable','quantity'=>$value['quantity'],'months'=>$value['months']]);
            }
            if (count($parts) === 2) {
                $evidence['forecast'] = array_intersect_key($forecast, array_flip(['algorithm_version','source_algorithm_version','signal','cutoff','product_id','location_id','base_unit']));
                return ['before'=>$parts[0], 'after'=>$parts[1], 'evidence'=>$evidence];
            }
        }
        $method = $settings['strategy'];
        $quality = 'exploratory';
        if ($rate === null && $manual !== null) {
            $rate = BigRational::of($manual);
            $method = 'manual_daily_rate';
            $quality = 'manual';
        }
        $exclusions = $reason === null ? [] : [$reason];
        if (!$authorized) {
            $exclusions[] = 'No se autorizó el uso exploratorio de la estrategia histórica.';
        }
        $first['excluded'] = $second['excluded'] = $exclusions;
        if ($rate !== null) {
            foreach ([&$first, &$second] as &$part) {
                $quantity = $rate->multipliedBy($part['days'])->simplified();
                $part = array_replace($part, ['rate'=>(string)$rate->toScale(12,RoundingMode::HALF_UP), 'rate_exact'=>(string)$rate->simplified(),
                    'quantity_exact'=>(string)$quantity, 'quantity'=>$quality === 'manual' ? (string)BigDecimal::of($manual)->multipliedBy($part['days'])
                        : (string)$quantity->toScale(12,RoundingMode::HALF_UP), 'method'=>$method, 'quality'=>$quality]);
            }
            unset($part);
            if ($quality === 'exploratory') {
                // El total se redondea una sola vez para no crear necesidades residuales entre tramos.
                $total = $rate->multipliedBy($first['days'] + $second['days'])->toScale(12, RoundingMode::HALF_UP);
                $second['quantity'] = (string)$total->minus($first['quantity']);
            }
        }
        return ['before'=>$first, 'after'=>$second, 'evidence'=>$evidence];
    }

    /** Acumula un bloque completo de días, sin extrapolar cantidades ausentes o tasas negativas.
     * @param ObservedDemandData $history Serie homogénea. @param string $field Señal.
     * @param int $offset Días anteriores al fin. @param int $length Duración exacta.
     * @return array Límites exclusivos, cantidades y tasa racional cuando es aplicable. */
    private function block(ObservedDemandData $history, string $field, int $offset, int $length): array
    {
        $end = $history->until->modify("-{$offset} days");
        $start = $end->modify("-{$length} days");
        $result = ['from'=>$start->format('Y-m-d'), 'until'=>$end->format('Y-m-d'), 'days'=>$length, 'quantity'=>null, 'has_gross_sales'=>null, 'rate'=>null];
        if ($start < $history->from) {
            return $result;
        }
        $sum = BigDecimal::zero();
        $sales = false;
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $day = $history->days[$date->format('Y-m-d')];
            $sum = $sum->plus($day[$field]);
            $sales = $sales || BigDecimal::of($day['gross_sales_units'])->isPositive();
        }
        $result['quantity'] = (string)$sum;
        $result['has_gross_sales'] = $sales;
        if (!$sum->isNegative()) {
            $result['rate'] = (string)BigRational::of($sum)->dividedBy($length)->simplified();
        }
        return $result;
    }

    /** Inicializa los límites auditables de un tramo. @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin exclusivo. @return array Tramo sin demanda acreditada. */
    private function interval(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return ['from'=>$from->format('Y-m-d'), 'until'=>$until->format('Y-m-d'), 'days'=>$from->diff($until)->days,
            'quantity'=>$from == $until ? '0' : null, 'rate'=>null, 'quality'=>$from == $until ? 'usable' : 'insufficient',
            'method'=>$from == $until ? 'empty_calendar_interval' : 'insufficient', 'excluded'=>[]];
    }
}
