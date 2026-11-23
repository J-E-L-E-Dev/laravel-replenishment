<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Forecasting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Operaciones decimales comunes sin conversiones a float. */
final class ForecastMath
{
    /**
     * Divide y redondea explícitamente a doce decimales.
     * @param string|int|BigDecimal $a Numerador.
     * @param string|int|BigDecimal $b Denominador no nulo.
     * @return string Cociente.
     * @throws \Brick\Math\Exception\DivisionByZeroException Si el denominador es cero.
     */
    public static function divide($a, $b): string { return (string) BigDecimal::of($a)->dividedBy($b, 12, RoundingMode::HALF_UP); }
    /**
     * Suma cantidades exactas.
     * @param array $values Valores decimales.
     * @return BigDecimal Suma.
     */
    public static function sum(array $values): BigDecimal { $total = BigDecimal::zero(); foreach ($values as $value) { $total = $total->plus($value); } return $total; }
    /**
     * Redondea sin alterar signo.
     * @param string|BigDecimal $value Cantidad.
     * @param int $scale Escala de presentación o cálculo.
     * @return string Decimal.
     */
    public static function round($value, int $scale = 12): string { return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP); }
}
