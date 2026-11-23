<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Forecasting;
use Asisnet\Replenishment\Contracts\ForecastModelInterface;
use Asisnet\Replenishment\DTO\ForecastOptions;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/** Familia de métodos estadísticos transparentes, con parámetros fijos identificados por candidato. */
final class StatisticalForecastModel implements ForecastModelInterface
{
    /**
     * Selecciona un método sin entrenar ni acceder a fuentes.
     * @param string $method mean, weighted, ses, trend, seasonal o tsb.
     * @param string $alpha Suavizamiento fijo para SES/TSB.
     * @throws InvalidArgumentException Si el método no existe.
     */
    public function __construct(private string $method, private string $alpha = '0.2')
    {
        if (!in_array($method, ['mean', 'weighted', 'ses', 'trend', 'seasonal', 'tsb'], true)) { throw new InvalidArgumentException('Método predictivo desconocido.'); }
    }
    /**
     * Identifica el candidato sin ambigüedad de parámetros.
     * @return string Nombre técnico.
     */
    public function name(): string { return in_array($this->method, ['ses', 'tsb'], true) ? $this->method.'_'.$this->alpha : $this->method; }
    /**
     * Aplica un método a un único origen; nunca adapta parámetros mirando el objetivo.
     * @param array $training Meses consecutivos verificados.
     * @param DateTimeImmutable $origin Primer mes posterior al ajuste.
     * @param int $horizon Mes objetivo.
     * @param ForecastOptions $options Ventanas y límites.
     * @param array|null $seasonality Índices calculados sólo con entrenamiento.
     * @return array|null Estimación exacta no negativa, o inelegibilidad explícita.
     */
    public function predict(array $training, DateTimeImmutable $origin, int $horizon, ForecastOptions $options, ?array $seasonality = null): ?array
    {
        $rows = array_slice($training, -$options->window); $n = count($rows);
        if ($n < $options->minimumTraining) { return null; }
        foreach ($rows as $row) { if ($row->quantity === null || $row->until > $origin) { return null; } }
        $rates = []; $quantities = []; $days = 0;
        foreach ($rows as $row) { $rates[] = ForecastMath::divide($row->quantity, $row->days); $quantities[] = $row->quantity; $days += $row->days; }
        $base = ForecastMath::divide(ForecastMath::sum($quantities), $days); $rate = $base; $factor = null; $trend = null; $details = [];
        if ($this->method === 'weighted') {
            $numerator = BigDecimal::zero(); $denominator = 0;
            foreach ($rows as $i => $row) { $weight = ($i + 1) ** (int) $options->recentWeight; $numerator = $numerator->plus(BigDecimal::of($row->quantity)->multipliedBy($weight)); $denominator += $row->days * $weight; }
            $rate = ForecastMath::divide($numerator, $denominator); $details['weight_exponent'] = $options->recentWeight;
        } elseif ($this->method === 'ses') {
            $rate = $rates[0]; $alpha = BigDecimal::of($this->alpha); $complement = BigDecimal::one()->minus($alpha);
            foreach (array_slice($rates, 1) as $value) { $rate = ForecastMath::round($alpha->multipliedBy($value)->plus($complement->multipliedBy($rate))); }
            $details['alpha'] = $this->alpha;
        } elseif ($this->method === 'trend') {
            if ($n < max(6, $options->minimumTraining)) { return null; }
            $xMean = ForecastMath::divide($n - 1, 2); $yMean = ForecastMath::divide(ForecastMath::sum($rates), $n);
            $covariance = BigDecimal::zero(); $variance = BigDecimal::zero();
            foreach ($rates as $i => $value) { $dx = BigDecimal::of($i)->minus($xMean); $covariance = $covariance->plus($dx->multipliedBy(BigDecimal::of($value)->minus($yMean))); $variance = $variance->plus($dx->multipliedBy($dx)); }
            $slope = ForecastMath::divide($covariance, $variance); $lastFit = BigDecimal::of($yMean)->plus(BigDecimal::of($slope)->multipliedBy(BigDecimal::of($n - 1)->minus($xMean)));
            $distance = BigDecimal::zero(); $power = BigDecimal::one();
            for ($i = 1; $i <= $horizon; $i++) { $power = $power->multipliedBy($options->damping); $distance = $distance->plus($power); }
            $raw = $lastFit->plus(BigDecimal::of($slope)->multipliedBy($distance));
            $cap = BigDecimal::max($base, $rates[$n - 1])->multipliedBy($options->maximumTrendFactor);
            $clamped = BigDecimal::min($cap, BigDecimal::max(0, $raw));
            $rate = ForecastMath::round($clamped);
            $trend = BigDecimal::of($base)->isPositive() ? ForecastMath::divide($rate, $base) : null;
            $details = ['slope_per_month' => $slope, 'damping' => $options->damping, 'rate_cap' => (string) $cap, 'clipped' => !$raw->isEqualTo($clamped)];
        } elseif ($this->method === 'seasonal') {
            if ($seasonality === null || $seasonality['quality'] !== 'recurrent') { return null; }
            // Se exige estabilidad de los doce factores, incluidos los meses habituales y bajos.
            foreach ($seasonality['months'] as $month) { if ($month['index'] === null || in_array($month['quality'], ['possible', 'unstable', 'insufficient'], true) || !BigDecimal::of($month['index'])->isPositive()) { return null; } }
            $adjusted = BigDecimal::zero();
            foreach ($rows as $row) { $index = $seasonality['months'][(int) $row->from->format('n')]['index']; $adjusted = $adjusted->plus(ForecastMath::divide($row->quantity, $index)); }
            $base = ForecastMath::divide($adjusted, $days);
            $factor = $seasonality['months'][(int) $origin->modify('+'.($horizon - 1).' months')->format('n')]['index'];
            $rate = ForecastMath::round(BigDecimal::of($base)->multipliedBy($factor));
            $details['seasonality_training_years'] = $seasonality['accepted_years'];
        } elseif ($this->method === 'tsb') {
            $positive = count(array_filter($quantities, fn (string $q): bool => BigDecimal::of($q)->isPositive()));
            if ($positive < $options->minimumPositiveMonths || BigDecimal::of(ForecastMath::divide($n - $positive, $n))->isLessThan($options->intermittentFraction)) { return null; }
            $size = null; $probability = null; $alpha = BigDecimal::of($this->alpha); $complement = BigDecimal::one()->minus($alpha);
            foreach ($rates as $i => $value) {
                $hasSale = BigDecimal::of($value)->isPositive();
                if ($size === null) { if ($hasSale) { $size = $value; $probability = ForecastMath::divide(1, $i + 1); } continue; }
                $probability = ForecastMath::round($alpha->multipliedBy($hasSale ? 1 : 0)->plus($complement->multipliedBy($probability)));
                if ($hasSale) { $size = ForecastMath::round($alpha->multipliedBy($value)->plus($complement->multipliedBy($size))); }
            }
            $rate = ForecastMath::round(BigDecimal::of($probability)->multipliedBy($size));
            $details = ['alpha_size' => $this->alpha, 'beta_occurrence' => $this->alpha, 'occurrence_probability' => $probability, 'positive_daily_size' => $size];
        }
        $start = $origin->modify('+'.($horizon - 1).' months'); $end = $start->modify('+1 month'); $futureDays = (int) $start->diff($end)->format('%a');
        return ['quantity' => ForecastMath::round(BigDecimal::max(0, $rate)->multipliedBy($futureDays)),
            'base_quantity' => ForecastMath::round(BigDecimal::of($base)->multipliedBy($futureDays)), 'daily_rate' => $rate,
            'seasonal_factor' => $factor, 'trend_factor' => $trend, 'training_periods' => $n, 'details' => $details];
    }
}
