<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Resultado inmutable con evidencia completa y salida compacta de planificación. */
final class ReplenishmentPlanData
{
    /**
     * Recibe un cálculo auditable, sin ejecutar compras.
     * @param array $report Cálculos, fuentes, hipótesis y estado.
     */
    public function __construct(public readonly array $report) {}
    /**
     * Selecciona el detalle necesario sin listar movimientos comerciales.
     * @param bool $detailed Incluye evidencia de entradas.
     * @return array Diagnóstico serializable.
     */
    public function audit(bool $detailed=false): array
    {
        $report=$this->report;if(!$detailed){unset($report['incoming']['lines'],$report['forecast']['context']['training']);}return $report;
    }
}
