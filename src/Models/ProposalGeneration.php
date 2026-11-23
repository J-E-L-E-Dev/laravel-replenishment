<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;
/** Estado durable de generación; las decisiones se auditan por separado. */
final class ProposalGeneration extends ReplenishmentModel
{
    /** @var string Tabla propia. */
    protected $table = 'replenishment_generations';
    /** @var array<string> Atributos de dominio autorizados. */
    protected $fillable = ['request_id','request_hash','supplier_id','location_id','actor_id','status','version','snapshot','proposal_id','selection_manifest'];
    /** @var array<string,string> JSON exacto y tipos estructurales. */
    protected $casts = ['selection_manifest'=>'array','freeze_cursor'=>'integer','snapshot' => 'array','version' => 'integer'];
    /** @var array<string> Ámbito y política congelados. */
    protected array $fixedFields = ['request_id','request_hash','supplier_id','location_id','actor_id','snapshot','selection_manifest'];

    /** @var array Manifiesto interno, omitido en consultas de progreso. */
    protected $hidden = ['selection_manifest'];
}
