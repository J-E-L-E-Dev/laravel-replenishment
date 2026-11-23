<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Database\Migrations;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Añade destino y evidencia a métricas propias, sin atribuir ubicación a registros antiguos. */
final class AddSupplierLeadTimeEvidence extends Migration
{
    /**
     * Amplía métricas manteniendo NULL en históricos anteriores.
     * @return void
     * @throws LogicException Si se omite el migrador propio.
     */
    public function up(): void
    {
        $this->schema()->table('replenishment_supplier_metrics',function(Blueprint $table):void{
            $table->string('location_id',100)->nullable();$table->text('lead_time_evidence')->nullable();
            $table->index(['product_supplier_id','location_id','calculated_at'],'rep_supplier_location_time_i');
        });
    }
    /**
     * Revierte únicamente esta ampliación; los campos nuevos se pierden sólo al solicitar rollback.
     * @return void
     * @throws LogicException Si se omite el migrador propio.
     */
    public function down(): void
    {
        $this->schema()->table('replenishment_supplier_metrics',function(Blueprint $table):void{
            $table->dropIndex('rep_supplier_location_time_i');$table->dropColumn(['location_id','lead_time_evidence']);
        });
    }
    /**
     * Obtiene esquema en contexto de migración explícita y conexión propia.
     * @return Builder Esquema aislado.
     * @throws LogicException Si se llama fuera del migrador propio.
     */
    private function schema(): Builder
    {
        $connection=app(PersistenceConnection::class);if(!$connection->isMigrating()){throw new LogicException('Utilice replenishment:migrate para mantener esquema y registro en la conexión propia.');}
        return $connection->connection()->getSchemaBuilder();
    }
}
