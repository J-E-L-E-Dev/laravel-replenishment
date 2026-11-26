<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

/** Intento durable de aplicar una revisión. Sólo el intento pendiente puede recibir un resultado. */
final class ParameterApplication extends ReplenishmentModel
{
    protected $table='replenishment_parameter_applications';
    protected $fillable=['review_id','attempt','actor_id','expected','changes','status','diagnosis'];
    protected $casts=['attempt'=>'integer','expected'=>'array','changes'=>'array'];
    protected array $requiredFields=['review_id','attempt','actor_id','expected','changes','status'];
    protected array $fixedFields=['review_id','attempt','actor_id','expected','changes'];
    protected array $allowedValues=['status'=>['pending','applied','conflict','error']];

    /** Protege la auditoría contra eliminación. @return void */
    protected static function booted(): void
    {
        parent::booted();
        static::deleting(static fn()=>throw new \LogicException('El intento de aplicación debe conservarse.'));
    }

    /** Comprueba que el resultado sólo se establece una vez. @return void @throws \LogicException Ante una reescritura. */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();
        if ($this->exists && $this->getRawOriginal('status')!=='pending' && $this->isDirty()) {
            throw new \LogicException('El resultado de una aplicación es definitivo.');
        }
    }
}
