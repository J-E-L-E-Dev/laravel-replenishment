<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/**
 * Representa una observación de existencia física; no descuenta reservas.
 *
 * Datos inmutables de transporte; el adaptador valida las reglas antes de crearlos.
 */
final class InventoryData
{
    /**
     * Recibe datos normalizados sin consultar ni modificar el origen.
     *
     * @param  string  $productId  Identificador opaco del producto.
     * @param  string  $locationId  Identificador opaco de la ubicación.
     * @param  string  $onHand  Cantidad física decimal en unidad base; admite negativos si el origen los registra.
     * @param  \DateTimeImmutable  $observedAt  Instante real de observación con zona horaria.
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $onHand,
        public readonly \DateTimeImmutable $observedAt,
    ) {}
}
