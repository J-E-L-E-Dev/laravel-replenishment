<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Contracts;
use Asisnet\Replenishment\DTO\ProductCostData;

/** Fuente opcional de costo de reposición; su moneda y unidad requieren evidencia del consumidor. */
interface ProductCostSource
{
    /** Consulta un costo sin escrituras. @param string $productId Identidad opaca. @return ProductCostData Valor y semántica comprobada o desconocida. @throws \RuntimeException Si la fuente no puede leerse. */
    public function forProduct(string $productId): ProductCostData;
}
