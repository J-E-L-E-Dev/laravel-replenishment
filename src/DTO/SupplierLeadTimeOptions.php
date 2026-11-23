<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Criterios explícitos de suficiencia, actualidad y tamaño máximo del diagnóstico. */
final class SupplierLeadTimeOptions
{
    /**
     * Valida parámetros estadísticos, sin inventar plazos comerciales.
     * @param int $minimumOrders Mínimo de órdenes independientes certificadas.
     * @param string $minimumCoverage Fracción mínima de la muestra del proveedor con evidencia certificada.
     * @param int $maximumAgeDays Edad máxima de la última recepción certificada.
     * @param int $maximumOrders Máximo de órdenes leídas, sin publicar truncamientos.
     * @param string $historicalMeasure complete_receipt o first_receipt; la primera entrega no equivale a entrega completa.
     * @throws InvalidArgumentException Si los criterios no son coherentes.
     */
    public function __construct(public readonly int $minimumOrders=3, public readonly string $minimumCoverage='0.50', public readonly int $maximumAgeDays=365,
        public readonly int $maximumOrders=250, public readonly string $historicalMeasure='complete_receipt')
    {
        if($minimumOrders<2||$maximumOrders<$minimumOrders||$maximumOrders>5000||$maximumAgeDays<0
            ||BigDecimal::of($minimumCoverage)->isLessThanOrEqualTo(0)||BigDecimal::of($minimumCoverage)->isGreaterThan(1)
            ||!in_array($historicalMeasure,['complete_receipt','first_receipt'],true)) { throw new InvalidArgumentException('Revise mínimos, cobertura, antigüedad y tipo de plazo histórico.'); }
    }
    /**
     * Expone criterios reproducibles.
     * @return array Opciones auditables.
     */
    public function audit(): array { return get_object_vars($this); }
}
