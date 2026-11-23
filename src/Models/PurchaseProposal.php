<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera editable de propuesta, sin emitir una orden de compra oficial.
 */
final class PurchaseProposal extends ReplenishmentModel
{
    /** @var string Tabla propia sin referencias a tablas del consumidor. */
    protected $table = 'replenishment_purchase_proposals';

    /** @var array<string> Atributos admitidos para asignación explícita. */
    protected $fillable = ['version','creation_key','derived_from_id','supplier_id', 'location_id', 'replenishment_run_id', 'status', 'created_by', 'reviewed_by', 'approved_at', 'notes'];

    /** @var array<string, string> Tipos exactos, fechas inmutables y JSON almacenado como texto. */
    protected $casts = ['version'=>'integer','derived_from_id'=>'integer',
        'replenishment_run_id' => 'integer',
        'approved_at' => 'immutable_datetime',
    ];

    /** @var array<string, mixed> Sólo valores estructurales; no se suponen cantidades comerciales. */
    protected $attributes = [
        'status' => 'draft',
    ];

    /** @var array<string> Atributos obligatorios para crear esta entidad. */
    protected array $requiredFields = ['supplier_id', 'status'];

    /** @var array<string> Identificadores externos que conservan su representación original. */
    protected array $externalIds = ['supplier_id', 'location_id', 'created_by', 'reviewed_by'];

    /** @var array<string> Identidades o configuración que requieren una nueva revisión para cambiar. */
    protected array $fixedFields = ['supplier_id', 'location_id', 'replenishment_run_id'];

    /** @var array<string, int> Límites de texto portables entre los motores soportados. */
    protected array $stringLengths = ['supplier_id' => 100, 'location_id' => 100, 'status' => 20, 'created_by' => 100, 'reviewed_by' => 100];

    /** @var array<string, array<string>> Estados o categorías persistidos sin enums nativos. */
    protected array $allowedValues = ['status' => ['draft', 'in_review', 'approved', 'rejected', 'cancelled']];

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
     * Consulta registros relacionados de PurchaseProposalItem mediante una clave interna.
     *
     * @return HasMany<PurchaseProposalItem, $this> Relación Eloquent sin acceso a datos del ERP.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseProposalItem::class, 'purchase_proposal_id');
    }

    /** Protege registros finales y evita eliminar evidencia físicamente. @return void */
    protected static function booted(): void
    {
        parent::booted();
        static::saving(function(self $model):void{if($model->exists&&in_array($model->getOriginal('status'),['approved','rejected','cancelled'],true)&&$model->isDirty()){throw new \LogicException('La propuesta finalizada es inmutable.');}});
        static::deleting(function():void{throw new \LogicException('Conserve el histórico; retire líneas o cancele la propuesta mediante el servicio.');});
    }
}
