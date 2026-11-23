<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Resolución operativa que mantiene configuración comercial y evidencia histórica separadas. */
final class SupplierLeadTimeResolutionData
{
    /**
     * Conserva origen seleccionado y diagnóstico sin crear compromisos comerciales.
     * @param SupplierLeadTimeData $history Evidencia histórica.
     * @param array $resolution Plazo, precedencia, vigencia y advertencias.
     */
    public function __construct(public readonly SupplierLeadTimeData $history,public readonly array $resolution) {}
    /**
     * Expone el resultado sin listados extensos de movimientos.
     * @return array Resumen para CLI.
     */
    public function audit(): array { return ['resolution'=>$this->resolution,'historical'=>$this->history->audit()]; }
}
