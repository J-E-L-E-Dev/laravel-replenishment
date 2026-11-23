<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\CommercialHistoryWindow;
use DateTimeImmutable;

/** Expone la historia comercial en unidades originales, independiente del agregado en unidad actual. */
interface CommercialHistorySource
{
    /**
     * Lee el intervalo y conserva el límite estadístico aun sin movimientos.
     *
     * @param string $productId Identidad textual del producto.
     * @param string $locationId Ámbito operativo.
     * @param DateTimeImmutable $from Inicio inclusivo a medianoche local.
     * @param DateTimeImmutable $until Fin exclusivo a medianoche local.
     * @return CommercialHistoryWindow Ventana y detalles sin conversiones implícitas.
     * @throws \InvalidArgumentException Ante parámetros o configuración inválidos.
     * @throws \RuntimeException Si falla la lectura, incluida la iteración.
     * @throws \LogicException Si falta la instalación requerida por el perfil.
     */
    public function window(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until): CommercialHistoryWindow;
}
