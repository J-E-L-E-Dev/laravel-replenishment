<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;
use InvalidArgumentException;

/** Atestación externa de cobertura documental completa; no se obtiene del simple éxito de una consulta. */
final class InventoryCoverageData
{
    /**
     * Identifica una cobertura previamente comprobada por el responsable del origen.
     * Incluye todos los cambios físicos y su orden real en la unidad declarada. Una referencia por sí sola no prueba esa condición.
     * @param string $productId Producto cubierto.
     * @param string $locationId Ubicación cubierta.
     * @param DateTimeImmutable $from Inicio de cobertura.
     * @param DateTimeImmutable $until Fin de cobertura.
     * @param string $baseUnit Unidad base comprobada.
     * @param string $evidenceReference Referencia auditable a la comprobación externa, nunca un indicador de confianza inventado.
     * @throws InvalidArgumentException Si falta identificación o el intervalo es inválido.
     */
    public function __construct(
        public readonly string $productId, public readonly string $locationId,
        public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until,
        public readonly string $baseUnit, public readonly string $evidenceReference,
    ) {
        if ($productId === '' || $locationId === '' || trim($baseUnit) === '' || trim($evidenceReference) === '' || $from >= $until) {
            throw new InvalidArgumentException('La cobertura requiere alcance, unidad, intervalo y evidencia documental explícita.');
        }
    }
}
