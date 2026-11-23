<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Parámetros de un horizonte mensual y sus hipótesis comerciales expresas. */
final class ReplenishmentOptions
{
    /**
     * Conserva fechas y valores explícitos sin convertir desconocidos en cero.
     * @param DateTimeImmutable $asOf Fecha civil de evaluación.
     * @param DateTimeImmutable $targetReceipt Fecha objetivo, no promesa del proveedor.
     * @param int $horizon Meses calendario de protección desde la fecha objetivo.
     * @param string|null $safetyUnits Seguridad manual del escenario, incluida cero explícita.
     * @param string|null $monthlyDemand Demanda manual por cada mes completo del horizonte.
     * @param string|null $consumptionBeforeReceipt Consumo manual total entre evaluación y recepción.
     * @param int|null $leadTimeDays Plazo manual del escenario; no cambia la relación comercial.
     * @param bool $assumeNoIncoming Permite sustituir sólo pendiente desconocido por cero simulado.
     * @param string|null $scenarioUnit Unidad declarada para cantidades manuales.
     * @param int|null $presentationId Revisión comercial elegida expresamente.
     * @throws InvalidArgumentException Si hay cantidades negativas, fechas u opciones incoherentes.
     */
    public function __construct(public readonly DateTimeImmutable $asOf,public readonly DateTimeImmutable $targetReceipt,public readonly int $horizon=1,
        public readonly ?string $safetyUnits=null,public readonly ?string $monthlyDemand=null,public readonly ?string $consumptionBeforeReceipt=null,
        public readonly ?int $leadTimeDays=null,public readonly bool $assumeNoIncoming=false,public readonly ?string $scenarioUnit=null,public readonly ?int $presentationId=null)
    {
        if($horizon<1||$horizon>6||$targetReceipt<$asOf||$targetReceipt>$asOf->modify('+2 years')||($leadTimeDays!==null&&($leadTimeDays<0||$leadTimeDays>3650))
            ||$asOf->format('H:i:s.u')!=='00:00:00.000000'||$targetReceipt->format('H:i:s.u')!=='00:00:00.000000'){
            throw new InvalidArgumentException('Use fechas civiles futuras, horizonte de uno a seis meses y plazo entre cero y 3650 días.');}
        foreach([$safetyUnits,$monthlyDemand,$consumptionBeforeReceipt] as $quantity){if($quantity!==null&&(!preg_match('/^\d+(?:\.\d{1,6})?$/D',$quantity)||BigDecimal::of($quantity)->isNegative())){throw new InvalidArgumentException('Las cantidades de escenario deben ser decimales no negativos, con hasta seis decimales.');}}
        if(($safetyUnits!==null||$monthlyDemand!==null||$consumptionBeforeReceipt!==null)&&($scenarioUnit===null||trim($scenarioUnit)==='')){throw new InvalidArgumentException('Declare la unidad base de las cantidades de escenario.');}
    }
    /**
     * Expone parámetros auditables con fechas serializables.
     * @return array Parámetros originales.
     */
    public function audit(): array
    {
        return array_replace(get_object_vars($this),['asOf'=>$this->asOf->format(DATE_ATOM),'targetReceipt'=>$this->targetReceipt->format(DATE_ATOM)]);
    }
}
