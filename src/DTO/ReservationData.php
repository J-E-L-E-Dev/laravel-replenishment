<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/**
 * Representa una reserva activa pendiente, normalizada por el adaptador.
 *
 * Datos inmutables de transporte; el adaptador valida las reglas antes de crearlos.
 */
final class ReservationData
{
    /** @var string Compromiso fijo de inventario. */
    public const FIXED = 'fixed';

    /** @var string Compromiso manual de inventario. */
    public const MANUAL = 'manual';

    /** @var string Reserva vigente de presupuesto. */
    public const QUOTATION = 'quotation';

    /** @var string Categoría conservadora para adaptadores anteriores sin clasificación. */
    public const UNCLASSIFIED = 'unclassified';

    /**
     * Recibe datos normalizados sin consultar ni modificar el origen.
     *
     * @param  string  $id  Identificador opaco y único de la línea de reserva.
     * @param  string  $productId  Identificador del producto.
     * @param  string  $locationId  Identificador de la ubicación.
     * @param  string|null  $quantity  Cantidad no negativa exacta; null conserva un compromiso desconocido.
     * @param  string  $kind  Clase de reserva; el valor predeterminado conserva llamadas anteriores de cuatro argumentos.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly ?string $quantity,
        public readonly string $kind = self::UNCLASSIFIED,
    ) {}
}
