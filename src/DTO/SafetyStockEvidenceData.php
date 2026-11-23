<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Evidencia externa de seguridad calibrada; el paquete no calcula ni certifica esa distribución. */
final class SafetyStockEvidenceData
{
    /**
     * Recibe una calibración previamente comprobada para el horizonte de protección exacto.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param string $baseUnit Unidad comparable.
     * @param string $quantity Seguridad no negativa.
     * @param DateTimeImmutable $from Inicio protegido.
     * @param DateTimeImmutable $until Fin exclusivo protegido.
     * @param string $calibrationReference Referencia auditable de errores o distribución calibrados; el texto solo no prueba calibración.
     * @throws InvalidArgumentException Si faltan datos coherentes.
     */
    public function __construct(public readonly string $productId,public readonly string $locationId,public readonly string $baseUnit,public readonly string $quantity,
        public readonly DateTimeImmutable $from,public readonly DateTimeImmutable $until,public readonly string $calibrationReference)
    {
        if($productId===''||$locationId===''||$baseUnit===''||trim($calibrationReference)===''||$from>=$until||BigDecimal::of($quantity)->isNegative()){
            throw new InvalidArgumentException('La seguridad calibrada requiere cantidad, alcance, período y evidencia externa comprobada.');}
    }
}
