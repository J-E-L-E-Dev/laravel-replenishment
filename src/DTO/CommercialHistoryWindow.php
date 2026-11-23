<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

use DateTimeImmutable;

/** Delimita la observación comercial incluso cuando la ventana efectiva carece de eventos. */
final class CommercialHistoryWindow
{
    /**
     * Conserva el intervalo civil y la proyección por movimiento, sin totalizar unidades distintas.
     *
     * @param DateTimeImmutable $requestedStart Inicio inclusivo solicitado.
     * @param DateTimeImmutable $effectiveStart Primer día admisible, que puede alcanzar o superar el fin.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param string|null $cutoffDate Última fecha excluida por el maestro.
     * @param iterable<InventoryMovementData> $movements Detalles de lectura secuencial con sus incidencias.
     */
    public function __construct(
        public readonly DateTimeImmutable $requestedStart,
        public readonly DateTimeImmutable $effectiveStart,
        public readonly DateTimeImmutable $until,
        public readonly ?string $cutoffDate,
        public readonly iterable $movements,
    ) {}
}
