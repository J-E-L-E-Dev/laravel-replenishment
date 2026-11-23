<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

/** Conserva cantidades firmadas en una única unidad histórica en la conexión propia. */
final class CommercialSalesUnit extends ReplenishmentModel
{
    use PreparesCommercialInsert;

    /** @var bool Los registros publicados son inmutables. */
    protected bool $historical = true;
    /** @var bool El instante de cálculo pertenece a la cabecera. */
    public $timestamps = false;
    /** @var string Tabla propia. */
    protected $table = 'replenishment_sales_unit_totals';
    /** @var array Campos admitidos al publicar. */
    protected $fillable = ['day_id', 'unit', 'unit_hash', 'event_count', 'gross_sales_units', 'returned_units', 'reversed_return_units', 'net_sales_units'];
    /** @var array Magnitudes verificadas del grupo. */
    protected array $requiredFields = ['day_id','unit','unit_hash','event_count','gross_sales_units','returned_units','reversed_return_units','net_sales_units'];
    /** @var array La unidad conserva su identidad textual. */
    protected array $stringLengths = ['unit' => 100];
    /** @var array Conteo de detalles individuales. */
    protected array $integerFields = ['event_count'];
    /** @var array Las devoluciones agregadas son magnitudes positivas. El neto admite negativos. */
    protected array $nonNegativeFields = ['gross_sales_units','returned_units','reversed_return_units'];
    /** @var array Tipos conservados en la lectura. */
    protected $casts = ['gross_sales_units' => \Asisnet\Replenishment\Casts\ExactDecimal::class.':24,6', 'returned_units' => \Asisnet\Replenishment\Casts\ExactDecimal::class.':24,6', 'reversed_return_units' => \Asisnet\Replenishment\Casts\ExactDecimal::class.':24,6', 'net_sales_units' => \Asisnet\Replenishment\Casts\ExactDecimal::class.':24,6'];
}
