<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Database\Migrations;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Añade únicamente control durable de importaciones, sin alterar las tablas comerciales anteriores. */
final class CreateSupplierImports extends Migration
{
    /** Crea operación, cursor, opciones y auditoría para reanudar lotes atómicos. @return void */
    public function up(): void
    {
        $this->schema()->create('replenishment_supplier_imports',function(Blueprint $table):void{
            $table->string('id',36)->primary();$table->string('supplier_id',100);$table->string('actor_id',100);
            $table->string('status',30);$table->unsignedInteger('version')->default(0);
            $table->string('cursor',100)->nullable();$table->text('options');$table->text('totals');
            $table->text('preview')->nullable();$table->text('last_batch')->nullable();$table->timestamps(6);
        });
    }
    /** Revierte sólo el control de importación bajo solicitud explícita. @return void */
    public function down(): void {$this->schema()->dropIfExists('replenishment_supplier_imports');}
    /**
     * Resuelve el esquema en un contexto autorizado de cualquiera de las modalidades.
     * @return Builder Esquema propio.
     * @throws LogicException Si falta contexto explícito de migración.
     */
    private function schema(): Builder
    {
        $own=app(PersistenceConnection::class);
        if(!$own->isMigrating()){throw new LogicException('Use una modalidad explícita de migración de Replenishment.');}
        return $own->connection()->getSchemaBuilder();
    }
}
