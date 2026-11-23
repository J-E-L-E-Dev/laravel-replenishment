<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Asisnet\Replenishment\DTO\AvailabilityData;
use Asisnet\Replenishment\DTO\InventoryData;
use Asisnet\Replenishment\DTO\ReservationData;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Resta compromisos normalizados a la existencia física sin conocer el almacenamiento de origen.
 */
final class AvailabilityCalculator
{
    /**
     * Agrega cada identidad de reserva una sola vez y propaga cantidades desconocidas.
     *
     * @param string $productId Identificador del producto.
     * @param string $locationId Alcance lógico solicitado.
     * @param InventoryData|null $inventory Observación física o dato desconocido.
     * @param iterable<ReservationData> $reservations Reservas vigentes normalizadas; vacío confirma ausencia.
     * @return AvailabilityData Desglose exacto, incluidos saldos negativos y reservas no clasificadas.
     * @throws InvalidArgumentException Si se mezclan alcances, identidades contradictorias o cantidades inválidas.
     */
    public function calculate(string $productId, string $locationId, ?InventoryData $inventory, iterable $reservations): AvailabilityData
    {
        if ($inventory !== null && ($inventory->productId !== $productId || $inventory->locationId !== $locationId)) {
            throw new InvalidArgumentException('La observación física no pertenece al producto y ubicación solicitados.');
        }

        $totals = array_fill_keys([
            ReservationData::FIXED, ReservationData::MANUAL,
            ReservationData::QUOTATION, ReservationData::UNCLASSIFIED,
        ], '0.000000');
        $seen = [];

        foreach ($reservations as $reservation) {
            if (!$reservation instanceof ReservationData || $reservation->productId !== $productId
                || $reservation->locationId !== $locationId || $reservation->id === ''
                || !array_key_exists($reservation->kind, $totals)) {
                throw new InvalidArgumentException('Reserva inválida o ajena al alcance solicitado.');
            }

            $quantity = $reservation->quantity === null ? null : ExactDecimal::normalize($reservation->quantity);
            if ($quantity !== null && ExactDecimal::compare($quantity, '0') < 0) {
                throw new InvalidArgumentException('Una reserva vigente no puede ser negativa.');
            }
            $identity = 'id:'.$reservation->id;
            $signature = [$reservation->kind, $quantity];
            if (array_key_exists($identity, $seen)) {
                if ($seen[$identity] !== $signature) {
                    throw new InvalidArgumentException('Una identidad de reserva presenta datos contradictorios.');
                }
                continue;
            }
            $seen[$identity] = $signature;
            $current = $totals[$reservation->kind];
            $totals[$reservation->kind] = $quantity === null || $current === null
                ? null : $this->add($current, $quantity);
        }

        $committed = '0.000000';
        foreach ($totals as $quantity) {
            $committed = $quantity === null || $committed === null ? null : $this->add($committed, $quantity);
        }
        $onHand = $inventory === null ? null : ExactDecimal::normalize($inventory->onHand);
        $available = $onHand === null || $committed === null ? null
            : ExactDecimal::normalize((string) BigDecimal::of($onHand)->minus($committed));
        $overcommitted = $available === null ? null : ExactDecimal::compare($available, '0') < 0;

        return new AvailabilityData(
            $productId, $locationId, $onHand,
            $totals[ReservationData::FIXED], $totals[ReservationData::MANUAL],
            $totals[ReservationData::QUOTATION], $totals[ReservationData::UNCLASSIFIED],
            $committed, $available, $available === null ? null : ($overcommitted ? '0.000000' : $available),
            $overcommitted, $inventory?->observedAt,
        );
    }

    /**
     * Suma decimales mediante aritmética exacta y comprueba el rango de salida.
     *
     * @param string $left Primer sumando normalizado.
     * @param string $right Segundo sumando normalizado.
     * @return string Suma exacta en escala de seis decimales.
     * @throws InvalidArgumentException Si la suma excede decimal(24,6).
     */
    private function add(string $left, string $right): string
    {
        return ExactDecimal::normalize((string) BigDecimal::of($left)->plus($right));
    }
}
