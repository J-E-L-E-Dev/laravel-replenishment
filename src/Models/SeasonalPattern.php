<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;

/**
 * Patrón histórico estacional almacenado sin calcular índices ni demanda.
 */
final class SeasonalPattern extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_seasonal_patterns';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['product_id', 'location_id', 'period_type', 'period_number', 'historical_years', 'seasonal_index', 'mean_demand', 'period_class', 'confidence', 'calculated_at', 'algorithm_version'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'period_number' => 'integer',
        'historical_years' => 'integer',
        'seasonal_index' => ExactDecimal::class.':24,6',
        'mean_demand' => ExactDecimal::class.':24,6',
        'confidence' => ExactDecimal::class.':9,6',
        'calculated_at' => 'immutable_datetime',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['product_id', 'period_type', 'period_number', 'calculated_at', 'algorithm_version'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['product_id', 'location_id'];

    /** @var array<string> Conteos y plazos enteros no negativos. */
    protected array $integerFields = ['period_number', 'historical_years'];

    /** @var array<string> Indicadores normalizados entre cero y uno. */
    protected array $ratioFields = ['confidence'];

    /** @var array<string> Magnitudes que deben ser estrictamente positivas. */
    protected array $positiveFields = ['period_number'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['seasonal_index'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['product_id' => 100, 'location_id' => 100, 'period_type' => 10, 'period_class' => 30, 'algorithm_version' => 100];

    /** @var array<string, array<string>> Estados o categorías persistidos sin enums nativos. */
    protected array $allowedValues = ['period_type' => ['monthly', 'weekly']];

    /** @var bool Los resultados calculados se agregan, nunca se sobrescriben. */
    protected bool $historical = true;

    /**
     * Valida el número mensual o semanal sin estimar índices estacionales.
     *
     * @throws \InvalidArgumentException Si el período excede 12 meses o 53 semanas.
     */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();

        if ($this->period_number > ($this->period_type === 'monthly' ? 12 : 53)) {
            throw new \InvalidArgumentException('Número de período fuera de rango.');
        }
    }
}
