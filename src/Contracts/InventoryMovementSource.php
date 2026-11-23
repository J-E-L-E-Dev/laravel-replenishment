<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\InventoryMovementData;
use DateTimeImmutable;

/**
 * Proporciona movimientos históricos sin reconstruir intervalos ni consultar un esquema específico.
 */
interface InventoryMovementSource
{
    /**
     * Lee un intervalo semiabierto en orden efectivo determinista y con memoria acotada.
     *
     * @param string $productId Identificador opaco del producto.
     * @param string $locationId Alcance lógico de inventario.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @return iterable<InventoryMovementData> Observaciones únicas con calidad y datos originales.
     * @throws \InvalidArgumentException Si el alcance o la precisión temporal no son admitidos.
     * @throws \RuntimeException Si faltan fechas necesarias para garantizar el intervalo o falla la lectura.
     */
    public function between(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until): iterable;
}
