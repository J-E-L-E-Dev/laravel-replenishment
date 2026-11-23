<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Observación histórica de cumplimiento y plazos basada en evidencia del proveedor.
 */
final class SupplierMetric extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_supplier_metrics';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['product_supplier_id', 'location_id', 'lead_time_evidence', 'analysis_start', 'analysis_end', 'calculated_at', 'algorithm_version', 'average_lead_time_days', 'minimum_lead_time_days', 'maximum_lead_time_days', 'lead_time_deviation', 'estimation_source', 'verified_orders', 'partial_receipts', 'quantity_fulfillment', 'date_fulfillment', 'confidence'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = [
        'product_supplier_id' => 'integer',
        'lead_time_evidence' => 'array',
        'analysis_start' => 'immutable_datetime',
        'analysis_end' => 'immutable_datetime',
        'calculated_at' => 'immutable_datetime',
        'average_lead_time_days' => ExactDecimal::class.':24,6',
        'minimum_lead_time_days' => ExactDecimal::class.':24,6',
        'maximum_lead_time_days' => ExactDecimal::class.':24,6',
        'lead_time_deviation' => ExactDecimal::class.':24,6',
        'verified_orders' => 'integer',
        'partial_receipts' => 'integer',
        'quantity_fulfillment' => ExactDecimal::class.':9,6',
        'date_fulfillment' => ExactDecimal::class.':9,6',
        'confidence' => ExactDecimal::class.':9,6',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['product_supplier_id', 'analysis_start', 'analysis_end', 'calculated_at', 'algorithm_version'];

    /** @var array<string> Conteos y plazos enteros no negativos. */
    protected array $integerFields = ['verified_orders', 'partial_receipts'];

    /** @var array<string> Indicadores normalizados entre cero y uno. */
    protected array $ratioFields = ['quantity_fulfillment', 'date_fulfillment', 'confidence'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['average_lead_time_days', 'minimum_lead_time_days', 'maximum_lead_time_days', 'lead_time_deviation'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['algorithm_version' => 100, 'estimation_source' => 100, 'location_id' => 100];

    /** @var array<string> El destino conserva su identidad opaca; los históricos previos pueden tener NULL. */
    protected array $externalIds = ['location_id'];

    /** @var array<array<string>> Ventanas semiabiertas con fin posterior al inicio. */
    protected array $dateRanges = [['analysis_start', 'analysis_end']];

    /** @var bool Los resultados calculados se agregan, nunca se sobrescriben. */
    protected bool $historical = true;

    /**
     * Consulta el registro propietario de ProductSupplier mediante una clave interna.
     *
     * @return BelongsTo<ProductSupplier, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function productSupplier(): BelongsTo
    {
        return $this->belongsTo(ProductSupplier::class, 'product_supplier_id');
    }
}
