<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

/** Registra el propietario, las etapas y la evidencia de un intento de procesamiento individual. */
final class GenerationAttempt extends ReplenishmentModel
{
    protected $table = 'replenishment_generation_attempts';
    protected $fillable = ['item_id','token','command_id','command_hash','action','actor_id','generation_version','stage','evidence'];
    protected $casts = ['evidence'=>'array','generation_version'=>'integer'];
    protected array $fixedFields = ['item_id','token','command_id','command_hash','action','actor_id','generation_version'];

    /** Protege el diagnóstico y la revisión de intentos finalizados. @throws \LogicException Si se intenta alterar un intento cerrado. */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();
        if ($this->exists && !in_array($this->getRawOriginal('stage'), ['preparing','calculating'], true) && $this->isDirty()) {
            throw new \LogicException('El intento ya está cerrado.');
        }
    }
}
