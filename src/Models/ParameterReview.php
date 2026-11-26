<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Revisión interna inmutable, con la evidencia de origen y los seis valores decididos. */
final class ParameterReview extends ReplenishmentModel
{
    protected $table = 'replenishment_parameter_reviews';
    protected $fillable = ['item_id','revision','actor_id','status','snapshot','decisions','reason'];
    protected $casts = ['revision'=>'integer','snapshot'=>'array','decisions'=>'array'];
    protected bool $historical = true;
    protected array $requiredFields = ['item_id','revision','actor_id','status','snapshot','decisions'];
    protected array $allowedValues = ['status'=>['unchanged','approved','not_applicable']];

    /** Consulta los intentos de esta revisión en orden de envío, incluidos rechazos y reservas abiertas.
     * @return HasMany Historial propio, paginable, con una sola fila por intento. */
    public function applications(): HasMany
    {
        return $this->hasMany(ParameterApplication::class,'review_id')->orderBy('attempt');
    }
}
