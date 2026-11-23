<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Separa el saldo puntual original de la calidad de su relación con la secuencia. */
final class StockObservationData
{
    /**
     * Conserva el movimiento sin modificar cantidades para conciliar discrepancias.
     * @param InventoryMovementData $movement Observación original.
     * @param string|null $observedBalance Saldo exacto legible o desconocido.
     * @param string|null $observedState positive o nonpositive; sólo describe el punto observado.
     * @param string $quality consistent, ambiguous, incomplete o unverified.
     * @param array<string> $issues Causas de calidad independientes del signo del saldo.
     */
    public function __construct(public readonly InventoryMovementData $movement, public readonly ?string $observedBalance,
        public readonly ?string $observedState, public readonly string $quality, public readonly array $issues) {}
}
