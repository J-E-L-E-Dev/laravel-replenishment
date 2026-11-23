<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\InventoryMovementData;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use RuntimeException;

/**
 * Contrasta observaciones consecutivas con memoria constante, sin calcular días agotados.
 */
final class HistoricalStockInspector
{
    /**
     * Señala huecos, orden incierto y diferencias entre efecto físico y stock resultante.
     *
     * @param iterable<InventoryMovementData> $movements Flujo de un producto y ubicación, ordenado por fecha.
     * @return iterable<InventoryMovementData> Mismas observaciones con indicadores de calidad.
     * @throws RuntimeException Si el flujo mezcla alcances o repite una identidad consecutiva.
     */
    public function inspect(iterable $movements): iterable
    {
        $previous = null;
        foreach ($movements as $movement) {
            $issues = [];
            if ($previous === null) {
                $issues[] = 'opening_observation_missing';
            } else {
                if ($movement->productId !== $previous->productId || $movement->locationId !== $previous->locationId
                    || $movement->id === $previous->id) {
                    throw new RuntimeException('Flujo histórico con alcance mezclado o identidad repetida.');
                }
                if ($movement->occurredAt < $previous->occurredAt) {
                    throw new RuntimeException('El flujo histórico no está ordenado por fecha efectiva.');
                }
                if ($movement->occurredAt->format('Y-m-d') === $previous->occurredAt->format('Y-m-d')
                    && ($movement->timePrecision === 'day' || $previous->timePrecision === 'day')) {
                    $issues[] = 'same_day_order_ambiguous';
                }
                if ($movement->sourceOrder !== null && $previous->sourceOrder !== null
                    && BigInteger::of($movement->sourceOrder)->isLessThan(BigInteger::of($previous->sourceOrder))) {
                    $issues[] = 'source_order_conflict';
                }
                if ($movement->historicalUnit !== $previous->historicalUnit) {
                    $issues[] = 'unit_changed';
                }
                if ($movement->baseUnit !== null && $movement->baseUnit === $previous->baseUnit
                    && $movement->inventoryDelta !== null && $movement->resultingStock !== null
                    && $previous->resultingStock !== null) {
                    $expected = BigDecimal::of($previous->resultingStock)->plus($movement->inventoryDelta);
                    if (!$expected->isEqualTo(BigDecimal::of($movement->resultingStock))) {
                        $issues[] = 'stock_delta_mismatch';
                    }
                } else {
                    $issues[] = 'opening_observation_missing';
                }
            }
            yield $movement->withIssues($issues);
            $previous = $movement;
        }
    }
}
