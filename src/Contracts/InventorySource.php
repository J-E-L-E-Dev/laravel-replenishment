<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\InventoryData;

/**
 * Consulta existencias físicas sin descontar reservas ni calcular disponibilidad.
 *
 * Implementación responsabilidad del consumidor; no debe escribir en el origen.
 */
interface InventorySource
{
    /**
     * Obtiene la observación actual de un producto en una ubicación.
     *
     * @param  string  $productId  Identificador del producto.
     * @param  string  $locationId  Identificador explícito de la ubicación.
     * @return InventoryData|null Observación física; null significa desconocida, nunca cero.
     *
     * @throws \RuntimeException Si el origen falla o sus datos no pueden normalizarse.
     */
    public function current(string $productId, string $locationId): ?InventoryData;
}
