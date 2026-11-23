<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Envuelve un movimiento normalizado y la referencia candidata de abastecimiento. */
final class PurchaseReceiptData
{
    /**
     * Conserva la referencia como candidata hasta contrastarla con la orden.
     * @param InventoryMovementData $movement Movimiento original normalizado una sola vez.
     * @param string $kind Tipo semántico receipt, return o return_reversal, independiente del código del origen.
     * @param string|null $supplierId Proveedor de encabezado inequívoco.
     * @param string|null $orderReference Texto original de referencia, incluso inválido.
     * @param string|null $orderDocument Componente documental candidato.
     * @param string|null $orderNumber Componente numérico candidato, sin perder ceros.
     * @param array<string> $issues Limitaciones de la referencia o del encabezado.
     */
    public function __construct(
        public readonly InventoryMovementData $movement,
        public readonly string $kind,
        public readonly ?string $supplierId,
        public readonly ?string $orderReference,
        public readonly ?string $orderDocument,
        public readonly ?string $orderNumber,
        public readonly array $issues = [],
    ) {}
}

