<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Valida los parámetros comerciales congelables de la demanda por política. */
final class PolicyDemandSettings
{
    public const DEFAULTS = [
        'signal' => 'verified_gross_sales', 'strategy' => 'historical_average',
        'prefer_forecast' => true, 'continuity' => 'strict', 'recent_days' => null,
        'blocks' => [], 'conservative' => 'none',
    ];

    /** Normaliza una configuración explícita. Los bloques se encadenan desde el corte hacia atrás.
     * @param array $values Opciones comerciales sin resultados ni actores.
     * @return array Parámetros completos con valores heredados seguros.
     * @throws InvalidArgumentException Ante opciones desconocidas o bloques incoherentes. */
    public static function normalize(array $values): array
    {
        if (array_diff_key($values, self::DEFAULTS)) {
            throw new InvalidArgumentException('La configuración de demanda contiene campos desconocidos.');
        }
        $settings = $values + self::DEFAULTS;
        foreach ([
            'strategy' => ['historical_average', 'recent_average', 'weighted_average'],
            'signal' => ['verified_gross_sales', 'verified_net_sales'],
            'continuity' => ['strict', 'assumed'],
            'conservative' => ['none', 'max_general_recent', 'manual'],
        ] as $field => $choices) {
            if (!in_array($settings[$field], $choices, true)) {
                throw new InvalidArgumentException("Opción de demanda inválida para {$field}.");
            }
        }
        if (!is_bool($settings['prefer_forecast'])) {
            throw new InvalidArgumentException('Indique expresamente la preferencia de pronóstico.');
        }
        $recent = $settings['recent_days'];
        if ($recent !== null && !in_array($recent, [30, 60, 90, 180], true)
            || $recent === null && ($settings['strategy'] === 'recent_average' || $settings['conservative'] === 'max_general_recent')) {
            throw new InvalidArgumentException('Seleccione una ventana reciente de 30, 60, 90 o 180 días.');
        }
        $blocks = $settings['blocks'];
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > 100
            || ($blocks !== []) !== ($settings['strategy'] === 'weighted_average')) {
            throw new InvalidArgumentException('Defina bloques consecutivos únicamente para el promedio ponderado.');
        }
        $total = BigDecimal::zero();
        $days = 0;
        foreach ($blocks as $block) {
            if (!is_array($block) || array_diff_key($block, ['days'=>true, 'weight'=>true])
                || !is_int($block['days'] ?? null) || $block['days'] < 1 || $block['days'] > 3650
                || !is_string($block['weight'] ?? null) || !preg_match('/^\d{1,3}(?:\.\d{1,6})?$/D', $block['weight'])
                || !BigDecimal::of($block['weight'])->isPositive()) {
                throw new InvalidArgumentException('Cada bloque requiere días enteros positivos y un porcentaje decimal positivo.');
            }
            $total = $total->plus($block['weight']);
            $days += $block['days'];
        }
        if ($blocks && (!$total->isEqualTo(100) || $days > 3650)) {
            throw new InvalidArgumentException('Los pesos deben sumar 100 % y los bloques deben caber en 3650 días.');
        }
        return $settings;
    }
}
