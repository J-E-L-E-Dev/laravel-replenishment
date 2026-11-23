<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

use DateTimeImmutable;

/** Resultado de una sola lectura o ejecución identificada. Conserva cantidades observadas separadas por unidad. */
final class CommercialSalesData
{
    /**
     * @param array $profile Parámetros semánticos, con producto, destino, corte y unidad actual.
     * @param DateTimeImmutable $from Inicio solicitado inclusivo.
     * @param DateTimeImmutable $effective Primer día admisible según el corte.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param DateTimeImmutable $calculatedAt Instante UTC de esta lectura.
     * @param array $days Días civiles con estado, incidencias y acumulados observados por unidad.
     * @param string $status complete, incomplete, unknown o refresh_required.
     * @param string $origin source o cache.
     * @param int|null $runId Identidad de publicación cuando procede.
     * @param string|null $limitation Diagnóstico de lectura o vigencia.
     */
    public function __construct(
        public readonly array $profile, public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $effective, public readonly DateTimeImmutable $until,
        public readonly DateTimeImmutable $calculatedAt, public readonly array $days,
        public readonly string $status, public readonly string $origin = 'source',
        public readonly ?int $runId = null, public readonly ?string $limitation = null,
    ) {}
}
