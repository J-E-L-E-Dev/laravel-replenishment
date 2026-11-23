<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

use Asisnet\Replenishment\DTO\SaleData;

/**
 * Consulta movimientos confirmados sin calcular demanda ni pronósticos.
 *
 * Implementación responsabilidad del consumidor; no debe escribir en el origen.
 */
interface SaleSource
{
    /**
     * Obtiene ventas y devoluciones sin duplicados en un intervalo semiabierto.
     * Los desgloses observados requieren kind y unidad base verificables en SaleData o su traza.
     * Mantener cantidades firmadas; no deducir la clasificación a partir del signo.
     *
     * @param  string  $productId  Identificador del producto.
     * @param  string  $locationId  Identificador de la ubicación.
     * @param  \DateTimeImmutable  $from  Inicio inclusivo con zona horaria.
     * @param  \DateTimeImmutable  $until  Fin exclusivo, estrictamente posterior al inicio.
     * @return iterable<SaleData> Eventos comerciales efectivos; una devolución o anulación contable es un evento firmado, no un borrador que deba descartarse. La cantidad ya incorpora el signo.
     *
     * @throws \InvalidArgumentException Si el intervalo es vacío o está invertido.
     * @throws \RuntimeException Si el origen falla, también durante una iteración diferida.
     */
    public function between(
        string $productId,
        string $locationId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $until,
    ): iterable;
}
