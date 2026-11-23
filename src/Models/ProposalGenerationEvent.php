<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;
/** Evento inmutable de decisiones comerciales. */
final class ProposalGenerationEvent extends ReplenishmentModel
{
    /** @var string Tabla propia. */
    protected $table = 'replenishment_generation_events';
    /** @var array<string> Atributos de dominio autorizados. */
    protected $fillable = ['generation_id','request_id','request_hash','actor_id','action','version','payload'];
    /** @var array<string,string> JSON exacto y tipos estructurales. */
    protected $casts = ['payload' => 'array','version' => 'integer'];
    /** @var bool Impide alterar eventos ya publicados. */
    protected bool $historical = true;
}
