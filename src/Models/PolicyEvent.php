<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;
/** Evento inmutable de decisiones comerciales. */
final class PolicyEvent extends ReplenishmentModel
{
    /** @var string Tabla propia. */
    protected $table = 'replenishment_policy_events';
    /** @var array<string> Atributos de dominio autorizados. */
    protected $fillable = ['policy_id','actor_id','action','payload'];
    /** @var array<string,string> JSON exacto y tipos estructurales. */
    protected $casts = ['payload' => 'array'];
    /** @var bool Impide alterar eventos ya publicados. */
    protected bool $historical = true;
}
