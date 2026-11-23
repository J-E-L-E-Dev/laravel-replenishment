<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;

/**
 * Observación histórica e inmutable de indicadores de producto y ubicación.
 */
final class ProductMetric extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_product_metrics';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['product_id', 'location_id', 'calculated_at', 'analysis_start', 'analysis_end', 'algorithm_version', 'observed_days', 'available_days', 'stockout_days', 'gross_sales', 'returns', 'reversed_returns', 'observed_sales_summary', 'net_sales', 'demand_30_days', 'demand_60_days', 'demand_90_days', 'demand_180_days', 'demand_365_days', 'daily_demand', 'weighted_daily_demand', 'variability', 'trend', 'rotation_class', 'abc_class', 'on_hand', 'fixed_reserved', 'manual_reserved', 'quotation_reserved', 'available_stock', 'recommended_safety_stock', 'reorder_point', 'recommended_minimum', 'recommended_maximum', 'coverage_days', 'quality', 'confidence'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'calculated_at' => 'immutable_datetime',
        'analysis_start' => 'immutable_datetime',
        'analysis_end' => 'immutable_datetime',
        'observed_days' => 'integer',
        'available_days' => 'integer',
        'stockout_days' => 'integer',
        'gross_sales' => ExactDecimal::class.':24,6',
        'returns' => ExactDecimal::class.':24,6',
        'reversed_returns' => ExactDecimal::class.':24,6',
        'observed_sales_summary' => 'array',
        'net_sales' => ExactDecimal::class.':24,6',
        'demand_30_days' => ExactDecimal::class.':24,6',
        'demand_60_days' => ExactDecimal::class.':24,6',
        'demand_90_days' => ExactDecimal::class.':24,6',
        'demand_180_days' => ExactDecimal::class.':24,6',
        'demand_365_days' => ExactDecimal::class.':24,6',
        'daily_demand' => ExactDecimal::class.':24,6',
        'weighted_daily_demand' => ExactDecimal::class.':24,6',
        'variability' => ExactDecimal::class.':24,6',
        'trend' => ExactDecimal::class.':24,6',
        'on_hand' => ExactDecimal::class.':24,6',
        'fixed_reserved' => ExactDecimal::class.':24,6',
        'manual_reserved' => ExactDecimal::class.':24,6',
        'quotation_reserved' => ExactDecimal::class.':24,6',
        'available_stock' => ExactDecimal::class.':24,6',
        'recommended_safety_stock' => ExactDecimal::class.':24,6',
        'reorder_point' => ExactDecimal::class.':24,6',
        'recommended_minimum' => ExactDecimal::class.':24,6',
        'recommended_maximum' => ExactDecimal::class.':24,6',
        'coverage_days' => ExactDecimal::class.':24,6',
        'quality' => ExactDecimal::class.':9,6',
        'confidence' => ExactDecimal::class.':9,6',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['product_id', 'calculated_at', 'analysis_start', 'analysis_end', 'algorithm_version'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['product_id', 'location_id'];

    /** @var array<string> Conteos y plazos enteros no negativos. */
    protected array $integerFields = ['observed_days', 'available_days', 'stockout_days'];

    /** @var array<string> Indicadores normalizados entre cero y uno. */
    protected array $ratioFields = ['quality', 'confidence'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['observed_days', 'available_days', 'stockout_days', 'gross_sales', 'returns', 'reversed_returns', 'variability', 'fixed_reserved', 'manual_reserved', 'quotation_reserved', 'recommended_safety_stock'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['product_id' => 100, 'location_id' => 100, 'algorithm_version' => 100, 'rotation_class' => 30, 'abc_class' => 10];

    /** @var array<array<string>> Ventanas semiabiertas con fin posterior al inicio. */
    protected array $dateRanges = [['analysis_start', 'analysis_end']];

    /** @var bool Los resultados calculados se agregan, nunca se sobrescriben. */
    protected bool $historical = true;
}

