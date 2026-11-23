<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\ProductData;

/**
 * Consulta el catálogo de productos sin acoplarlo a modelos del consumidor.
 *
 * Implementación responsabilidad del consumidor; no debe escribir en el origen.
 */
interface ProductSource
{
    /**
     * Busca un producto por identificador opaco.
     *
     * @param  string  $productId  Identificador del origen; conserva ceros iniciales.
     * @return ProductData|null Producto normalizado o null si no existe.
     *
     * @throws \RuntimeException Si el origen falla o sus datos no pueden normalizarse.
     */
    public function find(string $productId): ?ProductData;
}
