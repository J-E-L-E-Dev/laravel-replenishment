<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/**
 * Representa una línea de compra abierta pendiente de recepción.
 *
 * Datos inmutables de transporte; el adaptador valida las reglas antes de crearlos.
 */
final class PurchaseData
{
    /**
     * Recibe datos normalizados sin consultar ni modificar el origen.
     *
     * @param  string  $id  Identificador opaco y único de la línea de compra.
     * @param  string  $productId  Identificador del producto.
     * @param  string  $supplierId  Identificador del proveedor.
     * @param  string  $locationId  Identificador del destino de recepción.
     * @param  string  $pendingQuantity  Cantidad pendiente positiva en unidad base, convertida sólo con evidencia del origen.
     * @param  ?\DateTimeImmutable  $expectedAt  Fecha prevista con zona horaria, o null cuando no consta.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $supplierId,
        public readonly string $locationId,
        public readonly string $pendingQuantity,
        public readonly ?\DateTimeImmutable $expectedAt,
    ) {}
}
