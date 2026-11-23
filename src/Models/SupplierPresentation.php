<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Revisión explícita de una presentación y su conversión comercial exacta.
 */
final class SupplierPresentation extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_supplier_presentations';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['product_supplier_id', 'purchase_unit_id', 'revision', 'base_units_per_package', 'minimum_packages', 'package_multiple', 'active'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'product_supplier_id' => 'integer',
        'purchase_unit_id' => 'integer',
        'revision' => 'integer',
        'base_units_per_package' => ExactDecimal::class.':24,6',
        'minimum_packages' => ExactDecimal::class.':24,6',
        'package_multiple' => ExactDecimal::class.':24,6',
        'active' => 'boolean',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'revision' => 1,
        'active' => true,
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['product_supplier_id', 'purchase_unit_id', 'revision', 'base_units_per_package', 'minimum_packages', 'package_multiple'];

    /** @var array<string> Conteos y plazos enteros no negativos. */
    protected array $integerFields = ['revision'];

    /** @var array<string> Magnitudes que deben ser estrictamente positivas. */
    protected array $positiveFields = ['base_units_per_package', 'package_multiple', 'revision'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['minimum_packages'];

    /** @var array<string> Identidades o configuración que requieren una nueva revisión para cambiar. */
    protected array $fixedFields = ['product_supplier_id', 'purchase_unit_id', 'revision', 'base_units_per_package', 'minimum_packages', 'package_multiple'];

    /**
     * Limita la consulta a la última revisión por relación y unidad comercial activa.
     * Una revisión posterior inactiva también sustituye a la anterior.
     * @param \Illuminate\Database\Eloquent\Builder $query Consulta de presentaciones.
     * @return void
     */
    public function scopeCurrentCommercial($query): void
    {
        $table = $this->getTable();
        $query->where('active', true)->whereHas('purchaseUnit', fn ($unit) => $unit->where('active', true))
            ->whereNotExists(function ($newer) use ($table) {
                $newer->selectRaw('1')->from($table.' as newer')
                    ->whereColumn('newer.product_supplier_id', $table.'.product_supplier_id')
                    ->whereColumn('newer.purchase_unit_id', $table.'.purchase_unit_id')
                    ->whereColumn('newer.revision', '>', $table.'.revision');
            });
    }

    /**
     * Consulta el registro propietario de ProductSupplier mediante una clave interna.
     *
     * @return BelongsTo<ProductSupplier, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function productSupplier(): BelongsTo
    {
        return $this->belongsTo(ProductSupplier::class, 'product_supplier_id');
    }

    /**
     * Consulta el registro propietario de PurchaseUnit mediante una clave interna.
     *
     * @return BelongsTo<PurchaseUnit, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(PurchaseUnit::class, 'purchase_unit_id');
    }

    /**
     * Consulta registros relacionados de ReplenishmentResult mediante una clave interna.
     *
     * @return HasMany<ReplenishmentResult, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function results(): HasMany
    {
        return $this->hasMany(ReplenishmentResult::class, 'supplier_presentation_id');
    }

    /**
     * Consulta registros relacionados de PurchaseProposalItem mediante una clave interna.
     *
     * @return HasMany<PurchaseProposalItem, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function proposalItems(): HasMany
    {
        return $this->hasMany(PurchaseProposalItem::class, 'supplier_presentation_id');
    }

    /**
     * Captura la configuración conocida para explicar resultados y propuestas futuras.
     *
     * @return array<string, mixed> Identidades y valores exactos, sin consultar el ERP.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si falta una relación propia.
     */
    public function snapshot(): array
    {
        $unit = $this->purchaseUnit()->firstOrFail();
        $supplier = $this->productSupplier()->firstOrFail();

        return [
            'supplier_presentation_id' => $this->id,
            'product_supplier_id' => $supplier->id,
            'product_id' => $supplier->product_id,
            'supplier_id' => $supplier->supplier_id,
            'revision' => $this->revision,
            'purchase_unit_id' => $unit->id,
            'purchase_unit_code' => $unit->code,
            'purchase_unit_name' => $unit->name,
            'base_units_per_package' => $this->base_units_per_package,
            'minimum_packages' => $this->minimum_packages,
            'package_multiple' => $this->package_multiple,
        ];
    }
}
