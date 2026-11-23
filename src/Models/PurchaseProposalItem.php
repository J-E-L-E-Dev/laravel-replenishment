<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea editable de propuesta con cantidades sugeridas, aprobadas e instantánea comercial.
 */
final class PurchaseProposalItem extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_purchase_proposal_items';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['source_result_id','evidence','decision','removed_at','purchase_proposal_id', 'product_id', 'supplier_presentation_id', 'suggested_base_units', 'suggested_packages', 'approved_base_units', 'approved_packages', 'estimated_unit_cost', 'currency_code', 'adjustment_reason'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = ['source_result_id'=>'integer','evidence'=>'array','decision'=>'array','removed_at'=>'immutable_datetime',
        'purchase_proposal_id' => 'integer',
        'supplier_presentation_id' => 'integer',
        'presentation_snapshot' => 'array',
        'suggested_base_units' => ExactDecimal::class.':24,6',
        'suggested_packages' => ExactDecimal::class.':24,6',
        'approved_base_units' => ExactDecimal::class.':24,6',
        'approved_packages' => ExactDecimal::class.':24,6',
        'estimated_unit_cost' => ExactDecimal::class.':24,6',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['purchase_proposal_id', 'product_id', 'suggested_base_units'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['product_id'];

    /** @var array<string> Magnitudes que no pueden ser negativas. */
    protected array $nonNegativeFields = ['suggested_base_units', 'suggested_packages', 'approved_base_units', 'approved_packages', 'estimated_unit_cost'];

    /** @var array<string> Identidades o configuración que requieren una nueva revisión para cambiar. */
    protected array $fixedFields = ['purchase_proposal_id', 'product_id', 'presentation_snapshot','evidence','source_result_id'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['product_id' => 100, 'currency_code' => 3];

    /**
     * Consulta el registro propietario de PurchaseProposal mediante una clave interna.
     *
     * @return BelongsTo<PurchaseProposal, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PurchaseProposal::class, 'purchase_proposal_id');
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
     * Conserva la instantánea original salvo selección explícita de otra presentación.
     *
     * @throws \InvalidArgumentException Si la presentación no pertenece al producto o proveedor.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si falta la cabecera o presentación.
     */
    protected function prepareForPersistence(): void
    {
        if (! $this->exists || $this->isDirty('supplier_presentation_id')) {
            $this->presentation_snapshot = null;
            if ($this->supplier_presentation_id !== null) {
                $snapshot = $this->supplierPresentation()->firstOrFail()->snapshot();
                $proposal = $this->proposal()->firstOrFail();
                if ($snapshot['product_id'] !== $this->product_id || $snapshot['supplier_id'] !== $proposal->supplier_id) {
                    throw new \InvalidArgumentException('La presentación no corresponde al producto o proveedor de la propuesta.');
                }
                $this->presentation_snapshot = $snapshot;
            }
        }
    }

    /**
     * Verifica que un código monetario, cuando exista, use tres letras mayúsculas.
     *
     * @throws \InvalidArgumentException Si el código tiene un formato incompatible.
     */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();

        if ($this->currency_code !== null && ! preg_match('/^[A-Z]{3}$/D', $this->currency_code)) {
            throw new \InvalidArgumentException('currency_code debe contener tres letras mayúsculas.');
        }
    }

    /** Protege registros finales y evita eliminar evidencia físicamente. @return void */
    protected static function booted(): void
    {
        parent::booted();
        static::saving(function(self $model):void{if($model->proposal()->whereIn('status',['approved','rejected','cancelled'])->exists()){throw new \LogicException('No se modifican líneas de propuestas finalizadas.');}});
        static::deleting(function():void{throw new \LogicException('Conserve el histórico; retire líneas o cancele la propuesta mediante el servicio.');});
    }
}
