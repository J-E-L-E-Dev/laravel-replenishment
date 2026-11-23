<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\ReservationData;

/**
 * Consulta únicamente reservas activas con cantidad pendiente.
 *
 * Implementación responsabilidad del consumidor; no debe escribir en el origen.
 */
interface ReservationSource
{
    /**
     * Devuelve reservas pendientes sin duplicar líneas ni incluir cancelaciones.
     *
     * @param  string  $productId  Identificador del producto.
     * @param  string  $locationId  Identificador de la ubicación.
     * @return iterable<ReservationData> Reservas clasificadas en unidad base; vacío confirma ausencia, quantity null indica un compromiso desconocido. Cada identidad debe ser única en el alcance solicitado.
     *
     * @throws \RuntimeException Si el origen falla, también durante una iteración diferida.
     */
    public function activeFor(string $productId, string $locationId): iterable;
}
