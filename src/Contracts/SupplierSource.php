<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\SupplierData;

/**
 * Consulta proveedores sin inferir presentaciones comerciales.
 *
 * Implementación responsabilidad del consumidor; no debe escribir en el origen.
 */
interface SupplierSource
{
    /**
     * Busca un proveedor por identificador opaco.
     *
     * @param  string  $supplierId  Identificador del proveedor en el origen.
     * @return SupplierData|null Proveedor normalizado o null si no existe.
     *
     * @throws \RuntimeException Si el origen falla o sus datos no pueden normalizarse.
     */
    public function find(string $supplierId): ?SupplierData;
}
