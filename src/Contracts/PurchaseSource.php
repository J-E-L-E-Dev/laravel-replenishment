<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\PurchaseData;

/**
 * Consulta compras abiertas sin generar órdenes ni modificar recepciones.
 *
 * Implementación responsabilidad del consumidor; no debe escribir en el origen.
 */
interface PurchaseSource
{
    /**
     * Obtiene líneas pendientes, excluyendo canceladas y completamente recibidas.
     *
     * @param  string  $productId  Identificador del producto.
     * @param  string  $locationId  Identificador del destino de recepción.
     * @return iterable<PurchaseData> Líneas únicas en unidad base; vacío certifica ausencia de pendientes, nunca información insuficiente. Completar la iteración antes de publicar el total.
     *
     * @throws \RuntimeException Si el origen falla, no existe una conversión verificable o no puede verificarse el pendiente completo del producto. Consultar PurchaseHistorySource para la evidencia y sus limitaciones; descartar cualquier resultado parcial si falla el iterable.
     */
    public function pendingFor(string $productId, string $locationId): iterable;
}
