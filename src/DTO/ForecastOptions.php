<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Parámetros estadísticos explícitos; no son políticas de compra ni porcentajes de confianza. */
final class ForecastOptions
{
    /**
     * Valida ventanas, selección, reserva temporal y límites de extrapolación.
     * @param int $horizon Cantidad de meses futuros, entre uno y seis.
     * @param int $window Meses recientes de ajuste para métodos no estacionales.
     * @param int $minimumTraining Mínimo de meses consecutivos para estimación exploratoria.
     * @param int $minimumSelection Mínimo de orígenes anteriores a la reserva de validación.
     * @param int $validationOrigins Orígenes finales reservados, nunca usados para seleccionar.
     * @param int $maximumOrigins Máximo de orígenes retrospectivos por horizonte.
     * @param array<string> $alphas Parámetros fijos de suavizamiento comparados sólo con pasado.
     * @param string $recentWeight Exponente entero positivo de ponderación por posición.
     * @param string $damping Amortiguación de tendencia entre cero y uno.
     * @param string $maximumTrendFactor Tope de tasa respecto a la base reciente.
     * @param string $intermittentFraction Fracción mínima de meses nulos para TSB.
     * @param int $minimumPositiveMonths Mínimo de eventos mensuales positivos para TSB.
     * @param string $improvement Mejora relativa exigida para desplazar la media.
     * @param string $maximumWape Máximo WAPE retrospectivo para categoría utilizable.
     * @param string $maximumBias Máximo sesgo relativo absoluto para categoría utilizable.
     * @param int $maximumCacheAgeDays Antigüedad máxima de agregados para validar; cero exige cálculo del día.
     * @param bool $backtest Activa comparación retrospectiva; sin ella sólo se emite media exploratoria.
     * @throws InvalidArgumentException Si los parámetros son incoherentes.
     */
    public function __construct(public readonly int $horizon = 3, public readonly int $window = 12, public readonly int $minimumTraining = 3,
        public readonly int $minimumSelection = 6, public readonly int $validationOrigins = 3, public readonly int $maximumOrigins = 24,
        public readonly array $alphas = ['0.2', '0.5', '0.8'], public readonly string $recentWeight = '1', public readonly string $damping = '0.8',
        public readonly string $maximumTrendFactor = '2', public readonly string $intermittentFraction = '0.4', public readonly int $minimumPositiveMonths = 3,
        public readonly string $improvement = '0.05', public readonly string $maximumWape = '0.50', public readonly string $maximumBias = '0.25',
        public readonly int $maximumCacheAgeDays = 30, public readonly bool $backtest = true)
    {
        if ($horizon < 1 || $horizon > 6 || $minimumTraining < 2 || $window < $minimumTraining || $window > 60
            || $minimumSelection < 2 || $validationOrigins < 2 || $maximumOrigins < $minimumSelection + $validationOrigins || $maximumOrigins > 60
            || !in_array($recentWeight, ['1', '2', '3'], true) || $minimumPositiveMonths < 2 || $minimumPositiveMonths > $window || $maximumCacheAgeDays < 0 || count($alphas) < 1 || count($alphas) > 5) {
            throw new InvalidArgumentException('Revise horizontes, ventanas y mínimos de validación del pronóstico.');
        }
        foreach ([...$alphas, $damping, $intermittentFraction, $improvement] as $value) {
            if (!is_string($value) || BigDecimal::of($value)->isLessThanOrEqualTo(0) || BigDecimal::of($value)->isGreaterThan(1)) { throw new InvalidArgumentException('Los parámetros fraccionarios deben ser cadenas decimales en (0,1].'); }
        }
        if (BigDecimal::of($maximumTrendFactor)->isLessThan(1) || BigDecimal::of($maximumWape)->isNegative() || BigDecimal::of($maximumBias)->isNegative()) { throw new InvalidArgumentException('Los límites de error y crecimiento no pueden ser negativos.'); }
    }
    /**
     * Expone parámetros reproducibles.
     * @return array Auditoría sin estimaciones de confianza.
     */
    public function audit(): array { return get_object_vars($this); }
}
