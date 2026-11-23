<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/**
 * Describe un producto y su unidad base de inventario, sin presentación comercial.
 *
 * Datos inmutables de transporte; el adaptador valida las reglas antes de crearlos.
 */
final class ProductData
{
    /**
     * Recibe datos normalizados sin consultar ni modificar el origen.
     *
     * @param  string  $id  Identificador opaco del producto.
     * @param  string  $name  Nombre del producto.
     * @param  string  $stockUnit  Unidad base explícita del inventario; no equivale a un empaque del proveedor.
     * @param string|null $statisticsCutoffDate Último día excluido de las estadísticas, en formato Y-m-d.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $stockUnit,
        public readonly ?string $statisticsCutoffDate = null,
    ) {}
}
