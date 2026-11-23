<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/**
 * Identifica un proveedor sin inferir ofertas, empaques ni conversiones.
 *
 * Datos inmutables de transporte; el adaptador valida las reglas antes de crearlos.
 */
final class SupplierData
{
    /**
     * Recibe datos normalizados sin consultar ni modificar el origen.
     *
     * @param  string  $id  Identificador opaco del proveedor.
     * @param  string  $name  Nombre del proveedor.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
