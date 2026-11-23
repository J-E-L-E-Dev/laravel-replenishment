<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;
/** Evento inmutable con actor externo opaco, versión y evidencia anterior/posterior. */
final class ProposalEvent extends ReplenishmentModel
{
    /** @var string Tabla propia de auditoría. */
    protected $table='replenishment_proposal_events';
    /** @var array Campos explícitos del evento. */
    protected $fillable=['purchase_proposal_id','request_id','request_hash','actor_id','action','version','payload'];
    /** @var array Tipos estructurales, sin flotantes. */
    protected $casts=['payload'=>'array','version'=>'integer','purchase_proposal_id'=>'integer'];
    /** @var bool Impide editar o eliminar evidencia. */
    protected bool $historical=true;
    /** @var array Identidad externa sin relación al modelo User. */
    protected array $externalIds=['actor_id'];
    /** @var array Campos requeridos. */
    protected array $requiredFields=['purchase_proposal_id','request_id','request_hash','actor_id','action','version','payload'];
}
