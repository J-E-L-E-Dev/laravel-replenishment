<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Resultado histórico resumido, sin predicción ni porcentajes de confianza inventados. */
final class SeasonalityData
{
    /**
     * Recibe evidencia auditable sin movimientos individuales.
     * @param ObservedDemandData $source Serie comercial original.
     * @param array $report Cálculo y decisiones reproducibles.
     */
    public function __construct(public readonly ObservedDemandData $source, public readonly array $report) {}
    /**
     * Expone el diagnóstico y sus límites comerciales.
     * @return array Auditoría serializable.
     */
    public function audit(): array { return $this->report; }
}
