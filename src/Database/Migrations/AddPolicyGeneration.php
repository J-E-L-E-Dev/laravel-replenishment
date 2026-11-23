<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Database\Migrations;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\{Blueprint,Builder};
use LogicException;

/** Añade ejecución durable y auditoría comercial sin tocar cantidades ni propuestas históricas. */
final class AddPolicyGeneration extends Migration
{
    /** Crea tablas propias y actores de políticas, conservando NULL para registros anteriores. @return void */
    public function up(): void
    {
        $s=$this->schema();
        $s->table('replenishment_policies',function(Blueprint $t):void{$t->string('created_by',100)->nullable();$t->string('updated_by',100)->nullable();});
        $s->create('replenishment_policy_events',function(Blueprint $t):void{$t->id();$t->unsignedBigInteger('policy_id');$t->foreign('policy_id','rep_pe_policy')->references('id')->on('replenishment_policies')->onDelete('no action');$t->string('actor_id',100)->nullable();$t->string('action',30);$t->text('payload');$t->timestamps(6);});
        $s->create('replenishment_generations',function(Blueprint $t):void{$t->id();$t->string('request_id',36)->unique('rep_gen_uuid');$t->string('request_hash',64);$t->string('supplier_id',100);$t->string('location_id',100);$t->string('actor_id',100);$t->string('status',30);$t->unsignedInteger('version')->default(1);$t->text('snapshot');$t->unsignedBigInteger('proposal_id')->nullable();$t->foreign('proposal_id','rep_gen_proposal')->references('id')->on('replenishment_purchase_proposals')->onDelete('no action');$t->timestamps(6);});
        $s->create('replenishment_generation_items',function(Blueprint $t):void{$t->id();$t->unsignedBigInteger('generation_id');$t->foreign('generation_id','rep_gi_generation')->references('id')->on('replenishment_generations')->onDelete('no action');$t->unsignedBigInteger('relation_id');$t->foreign('relation_id','rep_gi_relation')->references('id')->on('replenishment_product_suppliers')->onDelete('no action');$t->string('product_id',100);$t->string('status',30)->default('pending');$t->text('result')->nullable();$t->text('decision')->nullable();$t->boolean('saved')->default(false);$t->timestamps(6);$t->unique(['generation_id','product_id'],'rep_gi_product');});
        $s->create('replenishment_generation_events',function(Blueprint $t):void{$t->id();$t->unsignedBigInteger('generation_id');$t->foreign('generation_id','rep_ge_generation')->references('id')->on('replenishment_generations')->onDelete('no action');$t->string('request_id',36)->unique('rep_ge_uuid');$t->string('request_hash',64);$t->string('actor_id',100);$t->string('action',30);$t->unsignedInteger('version');$t->text('payload');$t->timestamps(6);$t->unique(['generation_id','version'],'rep_ge_version');});
    }
    /** Revierte únicamente esta ampliación; no borra propuestas anteriores. @return void */
    public function down(): void
    {
        $s=$this->schema();foreach(['replenishment_generation_events','replenishment_generation_items','replenishment_generations','replenishment_policy_events'] as $table){$s->dropIfExists($table);}$s->table('replenishment_policies',function(Blueprint $t):void{$t->dropColumn(['created_by','updated_by']);});
    }
    /** Exige migración explícita en conexión propia. @return Builder Esquema aislado. @throws LogicException Sin contexto autorizado. */
    private function schema(): Builder {$own=app(PersistenceConnection::class);if(!$own->isMigrating()){throw new LogicException('Use replenishment:migrate explícitamente.');}return $own->connection()->getSchemaBuilder();}
}
