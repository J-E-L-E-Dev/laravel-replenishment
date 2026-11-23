<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

/** Conserva una ejecución publicada con su perfil completo en la conexión propia. */
final class CommercialSalesRun extends ReplenishmentModel
{
    /** @var bool Los registros publicados son inmutables. */
    protected bool $historical = true;
    /** @var bool El instante de cálculo pertenece a la cabecera. */
    public $timestamps = false;
    /** @var string Tabla propia. */
    protected $table = 'replenishment_sales_runs';
    /** @var array Campos admitidos al publicar. */
    protected $fillable = ['product_id', 'location_id', 'profile_hash', 'scope_hash', 'profile', 'status', 'requested_start', 'effective_start', 'until_date', 'calculated_at'];
    /** @var array Identidades opacas del origen. */
    protected array $externalIds = ['product_id', 'location_id'];
    /** @var array Sólo se publica una cabecera tras concluir la lectura. */
    protected array $allowedValues = ['status' => ['complete']];
    /** @var array Evidencia obligatoria de publicación. */
    protected array $requiredFields = ['profile_hash','scope_hash','profile','calculated_at','requested_start','effective_start','until_date'];
    /** @var array Tipos conservados en la lectura. */
    protected $casts = ['profile' => 'array', 'calculated_at' => 'immutable_datetime'];
}
