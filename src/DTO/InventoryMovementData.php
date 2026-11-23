<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

use DateTimeImmutable;

/**
 * Conserva una observación histórica y su trazabilidad sin afirmar continuidad de existencias.
 */
final class InventoryMovementData
{
    /**
     * Recibe magnitudes exactas y limitaciones explícitas de la observación.
     *
     * @param string $id Identidad única de línea, independiente del documento.
     * @param string $productId Identificador opaco de producto.
     * @param string $locationId Ubicación lógica.
     * @param string $document Código de documento, conservando ceros y sin concatenarlo con el número.
     * @param string $documentNumber Número de documento.
     * @param string $transactionType Transacción original normalizada como código.
     * @param DateTimeImmutable $occurredAt Fecha efectiva; su precisión se indica por separado.
     * @param string|null $originalQuantity Cantidad registrada con su signo original.
     * @param string|null $baseQuantity Misma cantidad y signo en unidad base verificable; null sin equivalencia.
     * @param string|null $inventoryDelta Efecto físico firmado; positivo entra, negativo sale.
     * @param string|null $demandQuantity Venta positiva, devolución negativa y reversión positiva; cero para movimientos físicos conocidos.
     * @param string|null $historicalUnit Unidad registrada, nunca empaque de proveedor.
     * @param string|null $baseUnit Unidad base identificada sin conversiones inferidas.
     * @param string|null $resultingStock Existencia resultante registrada, no observación continua.
     * @param string $timePrecision Precisión day o instant.
     * @param array<string> $issues Limitaciones de calidad, sin sustituir desconocidos por cero.
     * @param array<string, mixed> $metadata Evidencia adicional del origen.
     * @param string|null $sourceOrder Orden numérico del origen, que no representa una hora real.
     * @param string|null $historicalSaleQuantity Cantidad comercial firmada verificable en la unidad de venta del movimiento.
     * @param string|null $historicalSaleUnit Unidad de venta histórica, independiente de la unidad física.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $document,
        public readonly string $documentNumber,
        public readonly string $transactionType,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?string $originalQuantity,
        public readonly ?string $baseQuantity,
        public readonly ?string $inventoryDelta,
        public readonly ?string $demandQuantity,
        public readonly ?string $historicalUnit,
        public readonly ?string $baseUnit,
        public readonly ?string $resultingStock,
        public readonly string $timePrecision = 'day',
        public readonly array $issues = [],
        public readonly array $metadata = [],
        public readonly ?string $sourceOrder = null,
        public readonly ?string $historicalSaleQuantity = null,
        public readonly ?string $historicalSaleUnit = null,
    ) {}

    /**
     * Agrega limitaciones sin modificar los datos originales ni la instancia recibida.
     *
     * @param array<string> $issues Nuevas observaciones de calidad.
     * @return self Copia inmutable con limitaciones sin duplicados.
     */
    public function withIssues(array $issues): self
    {
        return new self(
            $this->id, $this->productId, $this->locationId, $this->document, $this->documentNumber,
            $this->transactionType, $this->occurredAt, $this->originalQuantity, $this->baseQuantity,
            $this->inventoryDelta, $this->demandQuantity, $this->historicalUnit, $this->baseUnit,
            $this->resultingStock, $this->timePrecision, array_values(array_unique([...$this->issues, ...$issues])),
            $this->metadata, $this->sourceOrder, $this->historicalSaleQuantity, $this->historicalSaleUnit,
        );
    }

    /**
     * Clasifica la observación para una reconstrucción futura, sin certificar un intervalo continuo.
     *
     * @return string incomplete, ambiguous o consistent según los datos conocidos.
     */
    public function reconstructionQuality(): string
    {
        if ($this->inventoryDelta === null || $this->resultingStock === null || $this->baseUnit === null
            || in_array('opening_observation_missing', $this->issues, true)) {
            return 'incomplete';
        }

        foreach (['same_day_order_ambiguous', 'stock_delta_mismatch', 'source_order_conflict',
            'header_date_mismatch', 'ambiguous_header', 'unknown_document_status', 'unit_changed'] as $issue) {
            if (in_array($issue, $this->issues, true)) {
                return 'ambiguous';
            }
        }

        return 'consistent';
    }
}
