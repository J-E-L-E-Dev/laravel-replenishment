<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;
use InvalidArgumentException;

/** Atestación externa de cobertura documental comprobada; no equivale a un estado del ERP ni a cierre comercial. */
final class LeadTimeCoverageData
{
    /**
     * Identifica una comprobación externa de todas las recepciones del alcance; el texto de referencia por sí solo no la demuestra.
     * @param string $document Código de orden.
     * @param string $number Número original.
     * @param string $productId Producto.
     * @param string $supplierId Proveedor.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio de cobertura íntegra.
     * @param DateTimeImmutable $until Fin exclusivo cubierto.
     * @param string $reference Referencia auditable a la verificación externa de completitud.
     * @param DateTimeImmutable|null $completeReceiptAt Fecha acreditada de recepción de toda la cantidad del producto, no cierre comercial.
     * @param string|null $completionReference Evidencia específica de esa recepción completa.
     * @throws InvalidArgumentException Si faltan alcance o evidencia coherente.
     */
    public function __construct(public readonly string $document, public readonly string $number, public readonly string $productId,
        public readonly string $supplierId, public readonly string $locationId, public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until,
        public readonly string $reference, public readonly ?DateTimeImmutable $completeReceiptAt=null, public readonly ?string $completionReference=null)
    {
        if($document===''||$number===''||$productId===''||$supplierId===''||$locationId===''||trim($reference)===''||$from>=$until
            ||(($completeReceiptAt===null)!==($completionReference===null))||($completionReference!==null&&trim($completionReference)==='')
            ||($completeReceiptAt!==null&&($completeReceiptAt<$from||$completeReceiptAt>=$until))) { throw new InvalidArgumentException('La cobertura requiere identidad, período y referencias verificadas; no se infiere de una conciliación.'); }
    }
    /**
     * Devuelve clave compuesta sin concatenaciones ambiguas.
     * @return string Identidad de la evidencia.
     */
    public function key(): string { return json_encode([$this->document,$this->number,$this->productId,$this->supplierId,$this->locationId],JSON_THROW_ON_ERROR); }
}
