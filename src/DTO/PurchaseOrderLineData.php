<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Representa una línea original; su unidad nunca implica una presentación del proveedor. */
final class PurchaseOrderLineData
{
    /**
     * Conserva cantidades declaradas separadas de cualquier conciliación.
     * @param string $document Código de orden.
     * @param string $number Número de orden.
     * @param string $lineNumber Identificador de línea dentro de la orden.
     * @param string $productId Producto original.
     * @param string $supplierId Proveedor declarado por la línea.
     * @param string|null $quantity Cantidad ordenada exacta o desconocida.
     * @param string|null $unit Unidad del producto registrada.
     * @param string|null $baseUnit Unidad base sólo si la equivalencia identidad es verificable.
     * @param DateTimeImmutable|null $expectedAt Fecha estimada de línea.
     * @param array<string,mixed> $metadata Cantidad recibida declarada, estados y valores originales.
     * @param array<string> $issues Causas de incertidumbre.
     */
    public function __construct(
        public readonly string $document,
        public readonly string $number,
        public readonly string $lineNumber,
        public readonly string $productId,
        public readonly string $supplierId,
        public readonly ?string $quantity,
        public readonly ?string $unit,
        public readonly ?string $baseUnit,
        public readonly ?DateTimeImmutable $expectedAt,
        public readonly array $metadata = [],
        public readonly array $issues = [],
    ) {}

    /**
     * Devuelve una identidad inequívoca, sin concatenar componentes ambiguos.
     * @return string Tupla serializada.
     */
    public function id(): string
    {
        return json_encode([$this->document, $this->number, $this->lineNumber], JSON_THROW_ON_ERROR);
    }
}

