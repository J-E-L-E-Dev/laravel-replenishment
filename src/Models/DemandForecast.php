<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;

/**
 * Pronóstico histórico por período, ubicación y versión del algoritmo.
 */
final class DemandForecast extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_demand_forecasts';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['product_id', 'location_id', 'generated_at', 'period_start', 'period_end', 'granularity', 'forecast_quantity', 'lower_bound', 'upper_bound', 'base_demand', 'seasonal_index', 'trend_factor', 'method', 'algorithm_version', 'confidence'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'generated_at' => 'immutable_datetime',
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
        'forecast_quantity' => ExactDecimal::class.':24,6',
        'lower_bound' => ExactDecimal::class.':24,6',
        'upper_bound' => ExactDecimal::class.':24,6',
        'base_demand' => ExactDecimal::class.':24,6',
        'seasonal_index' => ExactDecimal::class.':24,6',
        'trend_factor' => ExactDecimal::class.':24,6',
        'confidence' => ExactDecimal::class.':9,6',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['product_id', 'generated_at', 'period_start', 'period_end', 'granularity', 'method', 'algorithm_version'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['product_id', 'location_id'];

    /** @var array<string> Indicadores normalizados entre cero y uno. */
    protected array $ratioFields = ['confidence'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['forecast_quantity', 'lower_bound', 'upper_bound', 'base_demand', 'seasonal_index', 'trend_factor'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['product_id' => 100, 'location_id' => 100, 'granularity' => 20, 'method' => 100, 'algorithm_version' => 100];

    /** @var array<string, array<string>> Estados o categorías persistidos sin enums nativos. */
    protected array $allowedValues = ['granularity' => ['daily', 'weekly', 'monthly']];

    /** @var array<array<string>> Ventanas semiabiertas con fin posterior al inicio. */
    protected array $dateRanges = [['period_start', 'period_end']];

    /** @var bool Los resultados calculados se agregan, nunca se sobrescriben. */
    protected bool $historical = true;

    /**
     * Comprueba el orden de los límites conocidos sin completar datos faltantes.
     *
     * @throws \InvalidArgumentException Si los límites o la estimación central se contradicen.
     */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();

        foreach ([['lower_bound', 'forecast_quantity'], ['forecast_quantity', 'upper_bound'], ['lower_bound', 'upper_bound']] as [$lower, $upper]) {
            if ($this->{$lower} !== null && $this->{$upper} !== null
                && ExactDecimal::compare($this->{$lower}, $this->{$upper}) > 0) {
                throw new \InvalidArgumentException('Los límites del pronóstico están invertidos.');
            }
        }
    }
}
