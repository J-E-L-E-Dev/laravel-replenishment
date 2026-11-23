<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro de ejecución sin implementar motor ni transiciones automáticas.
 */
final class ReplenishmentRun extends ReplenishmentModel
{
    /** @var array<string> Contexto original necesario para interpretar resultados históricos. */
    protected array $fixedFields = ['analysis_start', 'analysis_end', 'algorithm_version', 'parameters_json', 'requested_by'];

    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_runs';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['started_at', 'finished_at', 'requested_by', 'analysis_start', 'analysis_end', 'algorithm_version', 'status', 'parameters_json', 'error_message'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'analysis_start' => 'immutable_datetime',
        'analysis_end' => 'immutable_datetime',
        'parameters_json' => 'array',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['analysis_start', 'analysis_end', 'algorithm_version', 'status'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['requested_by'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['requested_by' => 100, 'algorithm_version' => 100, 'status' => 20];

    /** @var array<string, array<string>> Estados o categorías persistidos sin enums nativos. */
    protected array $allowedValues = ['status' => ['pending', 'running', 'completed', 'failed', 'cancelled']];

    /** @var array<array<string>> Ventanas semiabiertas con fin posterior al inicio. */
    protected array $dateRanges = [['analysis_start', 'analysis_end']];

    /**
     * Consulta registros relacionados de ReplenishmentResult mediante una clave interna.
     *
     * @return HasMany<ReplenishmentResult, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function results(): HasMany
    {
        return $this->hasMany(ReplenishmentResult::class, 'replenishment_run_id');
    }

    /**
     * Consulta registros relacionados de PurchaseProposal mediante una clave interna.
     *
     * @return HasMany<PurchaseProposal, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(PurchaseProposal::class, 'replenishment_run_id');
    }

    /**
     * Rechaza una finalización anterior al inicio sin imponer un flujo de ejecución.
     *
     * @throws \InvalidArgumentException Si los instantes conocidos están invertidos.
     */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();

        if ($this->started_at !== null && $this->finished_at !== null && $this->finished_at < $this->started_at) {
            throw new \InvalidArgumentException('La ejecución no puede finalizar antes de comenzar.');
        }
    }
}
