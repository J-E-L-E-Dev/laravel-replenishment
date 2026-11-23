<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;
use InvalidArgumentException;

/** Ámbito explícito de consultas operativas; las fechas delimitan creación de cabeceras. */
final class DashboardScope
{
    /** Recibe filtros sin inferir almacenes ni proveedores. @param string $location Destino lógico obligatorio. @param string $from Fecha inicial inclusiva. @param string $until Fecha final exclusiva. @param string|null $supplier Código exacto. @param string|null $status Estado de propuesta. @throws InvalidArgumentException Ante ámbito vacío o intervalo mayor a 366 días. */
    public function __construct(public readonly string $location, public readonly string $from, public readonly string $until, public readonly ?string $supplier = null, public readonly ?string $status = null)
    {
        foreach ([$from,$until] as $date) { $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date); if (!$parsed || $parsed->format('Y-m-d')!==$date) { throw new InvalidArgumentException('Indique fechas civiles válidas.'); } }
        if ($location==='' || $from >= $until || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->days>366 || ($status!==null && !in_array($status,['draft','in_review','approved','rejected','cancelled'],true))) { throw new InvalidArgumentException('Ámbito inválido: el intervalo operativo admite hasta 366 días.'); }
    }
    /** Expone los filtros realmente aplicados. @return array Fechas semiabiertas y ámbito lógico. */
    public function toArray(): array { return get_object_vars($this); }
}
