<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado histórico con cantidades opcionales y fotografía de la presentación.
 */
final class ReplenishmentResult extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_results';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['replenishment_run_id', 'product_id', 'location_id', 'supplier_id', 'supplier_presentation_id', 'available_stock', 'verified_incoming', 'inventory_position', 'lead_time_demand', 'safety_stock', 'reorder_point', 'target_stock', 'required_base_units', 'suggested_packages', 'suggested_base_units', 'projected_coverage_days', 'suggested_order_date', 'expected_receipt_date', 'status', 'explanation'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'replenishment_run_id' => 'integer',
        'supplier_presentation_id' => 'integer',
        'presentation_snapshot' => 'array',
        'available_stock' => ExactDecimal::class.':24,6',
        'verified_incoming' => ExactDecimal::class.':24,6',
        'inventory_position' => ExactDecimal::class.':24,6',
        'lead_time_demand' => ExactDecimal::class.':24,6',
        'safety_stock' => ExactDecimal::class.':24,6',
        'reorder_point' => ExactDecimal::class.':24,6',
        'target_stock' => ExactDecimal::class.':24,6',
        'required_base_units' => ExactDecimal::class.':24,6',
        'suggested_packages' => ExactDecimal::class.':24,6',
        'suggested_base_units' => ExactDecimal::class.':24,6',
        'projected_coverage_days' => ExactDecimal::class.':24,6',
        'suggested_order_date' => 'immutable_date',
        'expected_receipt_date' => 'immutable_date',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['replenishment_run_id', 'product_id', 'status'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['product_id', 'location_id', 'supplier_id'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['verified_incoming', 'lead_time_demand', 'safety_stock', 'required_base_units', 'suggested_packages', 'suggested_base_units'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['product_id' => 100, 'location_id' => 100, 'supplier_id' => 100, 'status' => 20];

    /** @var array<string, array<string>> Estados o categorías persistidos sin enums nativos. */
    protected array $allowedValues = ['status' => ['pending', 'recommended', 'simulation', 'not_required', 'insufficient_data', 'error']];

    /** @var bool Los resultados calculados se agregan, nunca se sobrescriben. */
    protected bool $historical = true;

    /**
     * Consulta el registro propietario de ReplenishmentRun mediante una clave interna.
     *
     * @return BelongsTo<ReplenishmentRun, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReplenishmentRun::class, 'replenishment_run_id');
    }

    /**
     * Consulta el registro propietario de SupplierPresentation mediante una clave interna.
     *
     * @return BelongsTo<SupplierPresentation, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function supplierPresentation(): BelongsTo
    {
        return $this->belongsTo(SupplierPresentation::class, 'supplier_presentation_id');
    }

    /**
     * Captura una presentación explícita, validando sus identidades de producto y proveedor.
     *
     * @throws \InvalidArgumentException Si la presentación pertenece a otro producto o proveedor.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si no existe la presentación.
     */
    protected function prepareForPersistence(): void
    {
        if (! $this->exists && $this->supplier_presentation_id !== null) {
            $snapshot = $this->supplierPresentation()->firstOrFail()->snapshot();
            if ($snapshot['product_id'] !== $this->product_id || $snapshot['supplier_id'] !== $this->supplier_id) {
                throw new \InvalidArgumentException('La presentación no corresponde al producto y proveedor del resultado.');
            }
            $this->presentation_snapshot = $snapshot;
        }
    }
}
