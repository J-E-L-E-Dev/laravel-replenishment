<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Database\Migrations;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\{Blueprint,Builder};
use LogicException;
/** Añade evidencia por línea y auditoría de decisiones sin modificar el esquema histórico original. */
final class AddProposalWorkflow extends Migration
{
    /** Añade campos estructurales y eventos propios; no adopta propuestas previas. @return void */
    public function up(): void
    {
        $schema=$this->schema();$sqlite=app(PersistenceConnection::class)->connection()->getDriverName()==='sqlite';
        if($sqlite){$db=app(PersistenceConnection::class)->connection();$db->statement('ALTER TABLE replenishment_purchase_proposals ADD COLUMN derived_from_id INTEGER NULL REFERENCES replenishment_purchase_proposals(id) ON DELETE NO ACTION');$db->statement('ALTER TABLE replenishment_purchase_proposal_items ADD COLUMN source_result_id INTEGER NULL REFERENCES replenishment_results(id) ON DELETE NO ACTION');}
        $schema->table('replenishment_purchase_proposals',function(Blueprint $t)use($sqlite):void{$t->unsignedInteger('version')->default(0);$t->string('creation_key',36)->nullable();if(!$sqlite){$t->unsignedBigInteger('derived_from_id')->nullable();$t->foreign('derived_from_id','rep_proposal_parent')->references('id')->on('replenishment_purchase_proposals')->onDelete('no action');}});
        $schema->table('replenishment_purchase_proposal_items',function(Blueprint $t)use($sqlite):void{if(!$sqlite){$t->unsignedBigInteger('source_result_id')->nullable();$t->foreign('source_result_id','rep_item_result')->references('id')->on('replenishment_results')->onDelete('no action');}$t->text('evidence')->nullable();$t->text('decision')->nullable();$t->dateTime('removed_at',6)->nullable();});
        $schema->create('replenishment_proposal_events',function(Blueprint $t):void{$t->id();$t->unsignedBigInteger('purchase_proposal_id');$t->foreign('purchase_proposal_id','rep_event_proposal')->references('id')->on('replenishment_purchase_proposals')->onDelete('no action');$t->string('request_id',36)->unique('rep_event_request');$t->string('request_hash',64);$t->string('actor_id',100);$t->string('action',30);$t->unsignedInteger('version');$t->text('payload');$t->timestamps(6);$t->unique(['purchase_proposal_id','version'],'rep_event_version');});
    }
    /** Revierte sólo la ampliación; requiere respaldo de decisiones antes de uso real. @return void */
    public function down(): void
    {
        $s=$this->schema();$sqlite=app(PersistenceConnection::class)->connection()->getDriverName()==='sqlite';$s->dropIfExists('replenishment_proposal_events');
        $s->table('replenishment_purchase_proposal_items',function(Blueprint $t)use($sqlite):void{if(!$sqlite){$t->dropForeign('rep_item_result');}$t->dropColumn(['source_result_id','evidence','decision','removed_at']);});
        $s->table('replenishment_purchase_proposals',function(Blueprint $t)use($sqlite):void{if(!$sqlite){$t->dropForeign('rep_proposal_parent');}$t->dropColumn(['version','creation_key','derived_from_id']);});
    }
    /** Resuelve esquema propio sólo bajo migración explícita. @return Builder Destino propio. @throws LogicException Sin contexto autorizado. */
    private function schema(): Builder {$own=app(PersistenceConnection::class);if(!$own->isMigrating()){throw new LogicException('Use la migración explícita de Replenishment.');}return $own->connection()->getSchemaBuilder();}
}
