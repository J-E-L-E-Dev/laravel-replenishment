<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\InventoryParameterData;

/** Fotografía de lectura del maestro en la unidad de inventario del producto. */
interface InventoryParameterSource
{
    /** Consulta un bloque de identidades exactas. Un producto ausente se omite del mapa.
     * @param string[] $products Códigos de hasta cien productos.
     * @return array<string,InventoryParameterData> Fichas encontradas por código.
     * @throws \Throwable Si la lectura no puede completarse. */
    public function read(array $products): array;
}
