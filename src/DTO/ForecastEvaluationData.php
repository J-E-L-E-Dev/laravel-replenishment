<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Métricas de errores fuera del ajuste, separadas por horizonte y uso temporal. */
final class ForecastEvaluationData
{
    /**
     * Conserva conteos, errores y exclusiones sin porcentajes con denominador nulo.
     * @param array $metrics Agregados exactos.
     * @param array $observations Pares predicción/real y escala de entrenamiento.
     * @param array $excluded Períodos no comparables.
     */
    public function __construct(public readonly array $metrics, public readonly array $observations, public readonly array $excluded = []) {}
    /**
     * Expone métricas y evidencia de evaluación.
     * @return array Auditoría.
     */
    public function audit(): array { return ['metrics' => $this->metrics, 'observations' => $this->observations, 'excluded' => $this->excluded]; }
}
