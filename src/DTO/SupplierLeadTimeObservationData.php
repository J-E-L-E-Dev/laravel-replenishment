<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Observación por orden/producto, conservando evidencia documental y motivos de exclusión. */
final class SupplierLeadTimeObservationData
{
    /**
     * Recibe una observación sin modificar órdenes ni signos de movimientos.
     * @param array $evidence Identidad, fechas, vínculos y límites.
     */
    public function __construct(public readonly array $evidence) {}
    /**
     * Expone la evidencia resumida de una orden.
     * @return array Auditoría.
     */
    public function audit(): array { return $this->evidence; }
}
