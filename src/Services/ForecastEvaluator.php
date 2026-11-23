<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ForecastEvaluationData;
use Asisnet\Replenishment\Forecasting\ForecastMath;
use Brick\Math\BigDecimal;

/** Calcula errores firmados como pronóstico menos real, conservando denominadores nulos. */
final class ForecastEvaluator
{
    /**
     * Evalúa pares fuera del entrenamiento sin modificar cantidades originales.
     * @param array $observations Pares con predicted, actual y mase_scale calculada sólo en entrenamiento.
     * @param array $excluded Objetivos o ajustes no verificables.
     * @return ForecastEvaluationData MAE, WAPE, sesgo y MASE con conteos explícitos.
     */
    public function evaluate(array $observations, array $excluded = []): ForecastEvaluationData
    {
        $absolute = BigDecimal::zero(); $signed = BigDecimal::zero(); $actual = BigDecimal::zero(); $scaled = [];
        foreach ($observations as &$row) {
            $error = BigDecimal::of($row['predicted'])->minus($row['actual']); $abs = $error->abs();
            $row['signed_error'] = (string) $error; $row['absolute_error'] = (string) $abs;
            $absolute = $absolute->plus($abs); $signed = $signed->plus($error); $actual = $actual->plus(BigDecimal::of($row['actual'])->abs());
            $scale = $row['mase_scale'] ?? null;
            if ($scale !== null && BigDecimal::of($scale)->isPositive()) { $scaled[] = ForecastMath::divide($abs, $scale); }
        }
        unset($row); $n = count($observations);
        return new ForecastEvaluationData(['observations' => $n, 'excluded_periods' => count($excluded), 'absolute_error' => $n ? (string) $absolute : null,
            'signed_error' => $n ? (string) $signed : null, 'actual_units' => $n ? (string) $actual : null,
            'mae' => $n ? ForecastMath::divide($absolute, $n) : null, 'bias_units' => $n ? ForecastMath::divide($signed, $n) : null,
            'wape' => $actual->isPositive() ? ForecastMath::divide($absolute, $actual) : null,
            'bias_ratio' => $actual->isPositive() ? ForecastMath::divide($signed, $actual) : null,
            'mase' => count($scaled) === $n && $n > 0 ? ForecastMath::divide(ForecastMath::sum($scaled), $n) : null,
            'mase_observations' => count($scaled)], $observations, $excluded);
    }
    /**
     * Calcula la escala ingenua mensual sin datos del objetivo.
     * @param array $training Meses de ajuste.
     * @return string|null Media de diferencias absolutas consecutivas; cero no produce MASE.
     */
    public function scale(array $training): ?string
    {
        if (count($training) < 2) { return null; } $differences = [];
        for ($i = 1; $i < count($training); $i++) { $differences[] = (string) BigDecimal::of($training[$i]->quantity)->minus($training[$i - 1]->quantity)->abs(); }
        $scale = ForecastMath::divide(ForecastMath::sum($differences), count($differences));
        return BigDecimal::of($scale)->isPositive() ? $scale : null;
    }
}
