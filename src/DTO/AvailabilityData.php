<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

use DateTimeImmutable;

/**
 * Expone disponibilidad contable exacta sin persistir ni convertir desconocidos en cero.
 */
final class AvailabilityData
{
    /**
     * Recibe el desglose normalizado y conserva los saldos negativos.
     *
     * @param string $productId Identificador opaco del producto.
     * @param string $locationId Alcance lógico del inventario.
     * @param string|null $onHand Existencia física; null indica observación desconocida.
     * @param string|null $fixedReserved Reserva fija conocida o null.
     * @param string|null $manualReserved Reserva manual conocida o null.
     * @param string|null $quotationReserved Presupuestos vigentes conocidos o null.
     * @param string|null $unclassifiedReserved Reservas de adaptadores anteriores sin clasificación.
     * @param string|null $totalCommitted Suma de compromisos, desconocida si falta un componente.
     * @param string|null $available Existencia menos compromisos, sin truncar negativos.
     * @param string|null $positiveAvailable Máximo entre cero y disponible, sólo si éste se conoce.
     * @param bool|null $overcommitted Indica saldo negativo; null si no puede determinarse.
     * @param DateTimeImmutable|null $observedAt Instante de la observación física, no garantía de instantánea transaccional.
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly ?string $onHand,
        public readonly ?string $fixedReserved,
        public readonly ?string $manualReserved,
        public readonly ?string $quotationReserved,
        public readonly ?string $unclassifiedReserved,
        public readonly ?string $totalCommitted,
        public readonly ?string $available,
        public readonly ?string $positiveAvailable,
        public readonly ?bool $overcommitted,
        public readonly ?DateTimeImmutable $observedAt,
    ) {}
}
