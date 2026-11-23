<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;

/**
 * Parámetros comerciales explícitos; una política incompleta permanece inactiva.
 */
final class Policy extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_policies';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['name', 'description', 'analysis_days', 'target_coverage_days', 'service_level', 'default_lead_time_days', 'minimum_history_days', 'seasonal_minimum_years', 'forecast_horizon_days', 'active', 'demand_settings'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'demand_settings' => 'array',
        'analysis_days' => 'integer',
        'target_coverage_days' => 'integer',
        'service_level' => ExactDecimal::class.':9,6',
        'default_lead_time_days' => 'integer',
        'minimum_history_days' => 'integer',
        'seasonal_minimum_years' => 'integer',
        'forecast_horizon_days' => 'integer',
        'active' => 'boolean',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'active' => false,
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['name'];

    /** @var array<string> Conteos y plazos enteros no negativos. */
    protected array $integerFields = ['analysis_days', 'target_coverage_days', 'default_lead_time_days', 'minimum_history_days', 'seasonal_minimum_years', 'forecast_horizon_days'];

    /** @var array<string> Indicadores normalizados entre cero y uno. */
    protected array $ratioFields = ['service_level'];

    /** @var array<string> Magnitudes que deben ser estrictamente positivas. */
    protected array $positiveFields = ['analysis_days', 'target_coverage_days', 'minimum_history_days', 'seasonal_minimum_years', 'forecast_horizon_days', 'service_level'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['name' => 150];

    /**
     * Aplica únicamente parámetros predeterminados configurados explícitamente.
     */
    protected function prepareForPersistence(): void
    {
        if (! $this->exists) {
            foreach (config('replenishment.policy_defaults', []) as $field => $value) {
                if (in_array($field, $this->integerFields, true) || $field === 'service_level') {
                    if (! array_key_exists($field, $this->getAttributes())) {
                        $this->setAttribute($field, $value);
                    }
                }
            }
        }
    }

    /**
     * Impide activar una política cuyos parámetros comerciales sigan siendo desconocidos.
     *
     * @throws \InvalidArgumentException Si falta un parámetro explícito para la activación.
     */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();

        if ($this->demand_settings !== null) {
            if (!is_array($this->demand_settings)) {
                throw new \InvalidArgumentException('Los parámetros de demanda deben formar un objeto de configuración.');
            }
            $settings = \Asisnet\Replenishment\Services\PolicyDemandSettings::normalize($this->demand_settings);
            if ($settings['continuity'] === 'assumed' && trim((string)$this->continuity_authorized_by) === '') {
                throw new \InvalidArgumentException('La continuidad asumida requiere consentimiento de un usuario identificado.');
            }
            if ($this->analysis_days !== null && (array_sum(array_column($settings['blocks'], 'days')) > $this->analysis_days
                || ($settings['recent_days'] ?? 0) > $this->analysis_days)) {
                throw new \InvalidArgumentException('Las ventanas de estimación deben caber en los días de análisis.');
            }
        }
        if ($this->active) {
            foreach (array_merge($this->integerFields, ['service_level']) as $field) {
                if ($this->getAttribute($field) === null) {
                    throw new \InvalidArgumentException("Defina {$field} antes de activar la política.");
                }
            }
        }
    }
}
