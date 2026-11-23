<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Separa conciliación de movimientos observados y certeza de la obligación comercial pendiente. */
final class PurchaseReconciliationData
{
    /**
     * Transporta cantidades exactas y fechas útiles sin calcular promedios ni reposición.
     * @param PurchaseOrderData $order Orden original y todas sus líneas.
     * @param string $productId Producto conciliado a nivel de orden.
     * @param string $quality verified, partial, ambiguous o insufficient para la evidencia observada.
     * @param string|null $orderedQuantity Cantidad ordenada comparable.
     * @param string|null $receivedQuantity Suma de entradas observada y comprobada, no totalidad garantizada.
     * @param string|null $returnedQuantity Suma de devoluciones firmada, normalmente negativa.
     * @param string|null $restoredQuantity Suma de reversiones firmada.
     * @param string|null $netReceivedQuantity Neto firmado de movimientos comprobados.
     * @param string|null $calculatedPendingQuantity Diferencia matemática ordenado menos neto observado; puede ser negativa y no es obligación.
     * @param string|null $confirmedPendingQuantity Pendiente comercial confirmado; null si no está probado.
     * @param string $pendingQuality Confianza del pendiente, independiente de quality.
     * @param DateTimeImmutable|null $firstReceivedAt Primera entrada comprobada dentro de la evidencia consultada.
     * @param DateTimeImmutable|null $observedCompletionAt Fecha de cobertura de cantidad observada; no cierre comercial certificado.
     * @param array<PurchaseReceiptLinkData> $links Relaciones con granularidad y evidencia.
     * @param array<string> $issues Limitaciones, incluida la completitud del histórico.
     * @param DateTimeImmutable $observedUntil Límite exclusivo de movimientos consultados.
     */
    public function __construct(
        public readonly PurchaseOrderData $order,
        public readonly string $productId,
        public readonly string $quality,
        public readonly ?string $orderedQuantity,
        public readonly ?string $receivedQuantity,
        public readonly ?string $returnedQuantity,
        public readonly ?string $restoredQuantity,
        public readonly ?string $netReceivedQuantity,
        public readonly ?string $calculatedPendingQuantity,
        public readonly ?string $confirmedPendingQuantity,
        public readonly string $pendingQuality,
        public readonly ?DateTimeImmutable $firstReceivedAt,
        public readonly ?DateTimeImmutable $observedCompletionAt,
        public readonly array $links,
        public readonly array $issues,
        public readonly DateTimeImmutable $observedUntil,
    ) {}
}


