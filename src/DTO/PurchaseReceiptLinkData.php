<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Explica una relación comprobada o rechazada sin asignar líneas arbitrariamente. */
final class PurchaseReceiptLinkData
{
    /**
     * Conserva el resultado del contraste de una recepción candidata.
     * @param PurchaseReceiptData $receipt Evidencia de recepción o devolución.
     * @param bool $verified Indica coincidencia documental, de producto, proveedor, fecha y unidad.
     * @param string $granularity line, order_product o unresolved.
     * @param string|null $lineId Identidad de línea sólo cuando existe exactamente una candidata.
     * @param array<string> $issues Razones de rechazo o límite de granularidad.
     */
    public function __construct(
        public readonly PurchaseReceiptData $receipt,
        public readonly bool $verified,
        public readonly string $granularity,
        public readonly ?string $lineId,
        public readonly array $issues = [],
    ) {}
}

