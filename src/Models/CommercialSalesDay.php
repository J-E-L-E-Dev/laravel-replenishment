<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

/** Conserva la cobertura individual de un día en la conexión propia. */
final class CommercialSalesDay extends ReplenishmentModel
{
    use PreparesCommercialInsert;

    /** @var bool Los registros publicados son inmutables. */
    protected bool $historical = true;
    /** @var bool El instante de cálculo pertenece a la cabecera. */
    public $timestamps = false;
    /** @var string Tabla propia. */
    protected $table = 'replenishment_sales_days';
    /** @var array Campos admitidos al publicar. */
    protected $fillable = ['run_id', 'sales_date', 'state', 'event_count', 'issues'];
    /** @var array Estados que pueden publicarse tras una lectura completada. */
    protected array $allowedValues = ['state' => ['complete', 'zero_observed', 'outside_cutoff']];
    /** @var array Identidad y estado obligatorios. */
    protected array $requiredFields = ['run_id', 'sales_date', 'state'];
    /** @var array Conteos exactos del origen. */
    protected array $integerFields = ['event_count'];
    /** @var array Tipos conservados en la lectura. */
    protected $casts = ['event_count' => 'integer', 'issues' => 'array'];
}
