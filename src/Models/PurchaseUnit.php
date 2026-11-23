<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de presentaciones comerciales, independiente de la unidad de inventario.
 */
final class PurchaseUnit extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_purchase_units';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['code', 'name', 'description', 'active'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'active' => 'boolean',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'active' => true,
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['code', 'name'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['code' => 50, 'name' => 150];

    /**
     * Consulta registros relacionados de SupplierPresentation mediante una clave interna.
     *
     * @return HasMany<SupplierPresentation, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function presentations(): HasMany
    {
        return $this->hasMany(SupplierPresentation::class, 'purchase_unit_id');
    }
}
