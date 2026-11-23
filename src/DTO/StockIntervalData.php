<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Representa un tramo candidato sin confundir compatibilidad aritmética con continuidad demostrada. */
final class StockIntervalData
{
    /**
     * Recibe extremos observados; la precisión diaria no representa horas reales.
     * @param DateTimeImmutable $from Extremo inicial nominal.
     * @param DateTimeImmutable $until Extremo final nominal.
     * @param string $quality Calidad del tramo.
     * @param string|null $candidateState Estado físico compatible bajo continuidad, nunca disponibilidad comercial.
     * @param bool $confirmed Indica que se comprobaron orden, saldos, unidades, horas y cobertura externa.
     * @param array<string> $issues Limitaciones del tramo.
     * @param string|null $evidenceReference Evidencia externa aplicable cuando se confirma.
     */
    public function __construct(public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until,
        public readonly string $quality, public readonly ?string $candidateState, public readonly bool $confirmed,
        public readonly array $issues, public readonly ?string $evidenceReference = null) {}
}
