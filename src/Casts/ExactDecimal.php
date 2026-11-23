<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

/**
 * Transporta decimales como cadenas normalizadas sin convertirlos a coma flotante.
 */
final class ExactDecimal implements CastsAttributes
{
    /**
     * Configura la precisión y escala de la columna declarada en la migración.
     *
     * @param  int  $precision  Dígitos totales.
     * @param  int  $scale  Dígitos fraccionarios.
     *
     * @throws InvalidArgumentException Si la escala o precisión no es válida.
     */
    public function __construct(private int $precision = 24, private int $scale = 6)
    {
        if ($scale < 0 || $precision < 1 || $scale >= $precision) {
            throw new InvalidArgumentException('Precisión decimal inválida.');
        }
    }

    /**
     * Lee el decimal exacto entregado por el controlador.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model  Modelo propietario.
     * @param  string  $key  Nombre del atributo.
     * @param  mixed  $value  Valor almacenado.
     * @param  array<string, mixed>  $attributes  Atributos originales.
     * @return string|null Decimal normalizado o dato desconocido.
     *
     * @throws InvalidArgumentException Si el controlador devuelve un valor inexacto.
     */
    public function get($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : self::normalize($value, $this->precision, $this->scale);
    }

    /**
     * Rechaza entradas aproximadas antes de enviarlas al motor.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model  Modelo propietario.
     * @param  string  $key  Nombre del atributo.
     * @param  mixed  $value  Cadena decimal, entero exacto o null.
     * @param  array<string, mixed>  $attributes  Atributos actuales.
     * @return string|null Valor canónico para persistencia.
     *
     * @throws InvalidArgumentException Si hay desbordamiento, redondeo o entrada inválida.
     */
    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : self::normalize($value, $this->precision, $this->scale);
    }

    /**
     * Normaliza signo, ceros y escala sin efectuar aritmética aproximada.
     *
     * @param  mixed  $value  Valor decimal exacto.
     * @param  int  $precision  Dígitos totales disponibles.
     * @param  int  $scale  Dígitos fraccionarios disponibles.
     * @return string Decimal canónico con escala fija.
     *
     * @throws InvalidArgumentException Si el dato no cabe exactamente en la columna.
     */
    public static function normalize($value, int $precision = 24, int $scale = 6): string
    {
        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', (string) $value)) {
            throw new InvalidArgumentException('Se requiere una cadena decimal o un entero exacto.');
        }

        $negative = str_starts_with((string) $value, '-');
        $parts = explode('.', ltrim((string) $value, '-'), 2);
        $integer = ltrim($parts[0], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($parts[1] ?? '', '0');

        if (strlen($integer) > $precision - $scale || strlen($fraction) > $scale) {
            throw new InvalidArgumentException('El decimal excede la precisión o escala admitida.');
        }

        $sign = $negative && ($integer !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$integer.($scale > 0 ? '.'.str_pad($fraction, $scale, '0') : '');
    }

    /**
     * Compara escalares exactos y atiende la comparación de casts solicitada por Eloquent sin perder compatibilidad con llamadas de dos argumentos.
     *
     * @param  mixed  $left  Primer escalar, o modelo cuando Eloquent compara el cast.
     * @param  mixed  $right  Segundo escalar, o nombre del atributo en Eloquent.
     * @param mixed ...$context Dos valores originales recibidos de Eloquent, incluidos NULL.
     * @return int|bool Orden para dos escalares; igualdad para la llamada de cuatro argumentos de Eloquent.
     *
     * @throws InvalidArgumentException Si un valor no es representable.
     */
    public static function compare($left, $right, ...$context): int|bool
    {
        if ($left instanceof \Illuminate\Database\Eloquent\Model) {
            if (count($context) !== 2) { throw new InvalidArgumentException('La comparación del cast requiere dos valores.'); }
            if ($context[0] === null || $context[1] === null) { return $context[0] === $context[1]; }
            return self::compare($context[0], $context[1]) === 0;
        }
        $left = self::normalize($left);
        $right = self::normalize($right);
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = strcmp(
            str_pad(ltrim($left, '-'), 25, '0', STR_PAD_LEFT),
            str_pad(ltrim($right, '-'), 25, '0', STR_PAD_LEFT),
        );

        return $leftNegative ? -$comparison : $comparison;
    }
}
