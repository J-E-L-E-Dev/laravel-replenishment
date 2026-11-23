<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Conserva la identidad documental y el estado comercial original de una orden. */
final class PurchaseOrderData
{
    /**
     * Recibe una orden sin interpretar su estado como obligación pendiente.
     * @param string $document Código original.
     * @param string $number Número original con ceros iniciales.
     * @param string $supplierId Proveedor del encabezado.
     * @param string $locationId Destino lógico.
     * @param DateTimeImmutable|null $orderedAt Fecha de emisión, no de recepción.
     * @param DateTimeImmutable|null $expectedAt Estimación del encabezado.
     * @param string|null $commercialStatus Estado original sin regla inferida.
     * @param array<PurchaseOrderLineData> $lines Líneas identificadas por tres componentes.
     * @param array<string> $issues Limitaciones del documento.
     */
    public function __construct(
        public readonly string $document,
        public readonly string $number,
        public readonly string $supplierId,
        public readonly string $locationId,
        public readonly ?DateTimeImmutable $orderedAt,
        public readonly ?DateTimeImmutable $expectedAt,
        public readonly ?string $commercialStatus,
        public readonly array $lines,
        public readonly array $issues = [],
    ) {}
}

