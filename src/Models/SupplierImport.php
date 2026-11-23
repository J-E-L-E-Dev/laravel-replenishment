<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;

/** Control durable de una carga explícita, con auditoría y cursor confirmado junto a sus escrituras. */
final class SupplierImport extends ReplenishmentModel
{
    /** @var string Tabla de control propia. */
    protected $table='replenishment_supplier_imports';
    /** @var bool Identidad UUID, nunca secuencia comercial. */
    public $incrementing=false;
    /** @var string Identidad textual. */
    protected $keyType='string';
    /** @var array Campos de control escritos exclusivamente por el servicio. */
    protected $fillable=['id','supplier_id','actor_id','status','version','cursor','options','totals','preview','last_batch'];
    /** @var array Auditoría sin cantidades flotantes. */
    protected $casts=['version'=>'integer','options'=>'array','totals'=>'array','preview'=>'array','last_batch'=>'array'];
}
