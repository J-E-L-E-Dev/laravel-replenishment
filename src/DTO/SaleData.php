<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/**
 * Representa un evento comercial de venta, devolución o reversión sin inferir demanda potencial.
 *
 * Datos inmutables de transporte; el adaptador valida las reglas antes de crearlos.
 */
final class SaleData
{
    /**
     * Recibe datos normalizados sin consultar ni modificar el origen.
     *
     * @param  string  $id  Identificador opaco y único del movimiento.
     * @param  string  $productId  Identificador del producto.
     * @param  string  $locationId  Identificador de la ubicación de salida o devolución.
     * @param  string  $quantity  Decimal firmado en unidad base: positivo para venta o reversión y negativo para devolución; el signo no clasifica el evento.
     * @param  \DateTimeImmutable  $occurredAt  Instante efectivo del movimiento con zona horaria.
     * @param InventoryMovementData|null $movement Trazabilidad y limitaciones históricas opcionales; preserva el constructor anterior.
     * @param string|null $kind Tipo semántico sale, return o return_reversal; null significa clasificación desconocida.
     * @param string|null $baseUnit Unidad base verificada; el motor también puede obtenerla del movimiento adjunto.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $quantity,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?InventoryMovementData $movement = null,
        public readonly ?string $kind = null,
        public readonly ?string $baseUnit = null,
    ) {}
}
