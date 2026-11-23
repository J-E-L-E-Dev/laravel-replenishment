<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relación comercial de un producto externo con un proveedor externo.
 */
final class ProductSupplier extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_product_suppliers';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['product_id', 'supplier_id', 'is_preferred', 'active', 'manual_lead_time_days', 'notes'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'is_preferred' => 'boolean',
        'active' => 'boolean',
        'manual_lead_time_days' => 'integer',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'is_preferred' => false,
        'active' => true,
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['product_id', 'supplier_id'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['product_id', 'supplier_id'];

    /** @var array<string> Conteos y plazos enteros no negativos. */
    protected array $integerFields = ['manual_lead_time_days'];

    /** @var array<string> Identidades o configuración que requieren una nueva revisión para cambiar. */
    protected array $fixedFields = ['product_id', 'supplier_id'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['product_id' => 100, 'supplier_id' => 100];

    /**
     * Consulta registros relacionados de SupplierPresentation mediante una clave interna.
     *
     * @return HasMany<SupplierPresentation, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function presentations(): HasMany
    {
        return $this->hasMany(SupplierPresentation::class, 'product_supplier_id');
    }

    /**
     * Recupera las revisiones vigentes sin descartar alternativas ambiguas por su factor.
     * @return HasMany Revisiones activas de las unidades activas de esta relación.
     */
    public function currentPresentations(): HasMany
    {
        return $this->presentations()->currentCommercial();
    }

    /**
     * Identifica la única revisión íntegra para preparar una compra.
     * @return int|null Identidad de revisión o ausencia si falta, es ambigua o tiene parámetros inválidos.
     */
    public function purchasePresentationId(): ?int
    {
        $current = $this->currentPresentations;
        if (!$this->active || $current->count() !== 1) {
            return null;
        }
        $presentation = $current->first();
        $unit = $presentation->purchaseUnit;
        if (!$unit?->active || trim((string) $unit->code) === '' || trim((string) $unit->name) === '' || $presentation->revision < 1) {
            return null;
        }
        try {
            foreach (['base_units_per_package', 'minimum_packages', 'package_multiple'] as $field) {
                $value = $presentation->{$field};
                if ($value === null || \Asisnet\Replenishment\Casts\ExactDecimal::compare($value, 0) < ($field === 'minimum_packages' ? 0 : 1)) {
                    return null;
                }
            }
        } catch (\InvalidArgumentException $error) {
            return null;
        }
        return (int) $presentation->id;
    }

    /**
     * Consulta registros relacionados de SupplierMetric mediante una clave interna.
     *
     * @return HasMany<SupplierMetric, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(SupplierMetric::class, 'product_supplier_id');
    }
}
