<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Database\Migrations;

use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Añade reservas recuperables y revisiones de trabajo sin recalificar generaciones históricas. */
final class AddGenerationWork extends Migration
{
    /** Resuelve el esquema del migrador propio. @return Builder Esquema autorizado. @throws LogicException Fuera del migrador. */
    private function schema(): Builder
    {
        $own = app(PersistenceConnection::class);
        if (!$own->isMigrating()) {
            throw new LogicException('Use el migrador de Replenishment.');
        }
        return $own->connection()->getSchemaBuilder();
    }

    /** Añade índices de seguimiento y el historial durable de intentos por producto. @return void */
    public function up(): void
    {
        $schema = $this->schema();
        $schema->table('replenishment_generations',function (Blueprint $t): void {
            $t->longText('selection_manifest')->nullable();
            $t->unsignedInteger('freeze_cursor')->default(0);
        });
        $schema->table('replenishment_generation_items', function (Blueprint $t): void {
            $t->string('technical_state',24)->nullable();
            $t->string('diagnosis',40)->nullable();
            $t->string('bulk_candidate',20)->nullable();
            $t->string('statistics_origin',20)->nullable();
            $t->boolean('requires_review')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->dateTime('lease_until',6)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('preparation')->nullable();
            $t->text('row_summary')->nullable();
            $t->longText('revised_result')->nullable();
            $t->text('previous_choice')->nullable();
            $t->index(['generation_id','status','id'],'rep_gi_pending');
            $t->index(['generation_id','lease_token'],'rep_gi_lease');
        });
        $schema->create('replenishment_generation_attempts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('item_id');
            $t->foreign('item_id','rep_ga_item')->references('id')->on('replenishment_generation_items')->onDelete('no action');
            $t->uuid('token')->unique();
            $t->uuid('command_id')->index('rep_ga_command');
            $t->string('command_hash',64);
            $t->string('action',24);
            $t->string('actor_id',100);
            $t->unsignedInteger('generation_version');
            $t->string('stage',24);
            $t->longText('evidence')->nullable();
            $t->timestamps(6);
            $t->index(['item_id','id'],'rep_ga_history');
        });
    }

    /** Revierte las estructuras operativas de D2 en su conexión. @return void */
    public function down(): void
    {
        $schema = $this->schema();
        foreach (['selection_manifest','freeze_cursor'] as $field) {
            $schema->table('replenishment_generations',fn (Blueprint $t) => $t->dropColumn($field));
        }
        $schema->drop('replenishment_generation_attempts');
        $schema->table('replenishment_generation_items',function (Blueprint $t): void {
            $t->dropIndex('rep_gi_pending');
            $t->dropIndex('rep_gi_lease');
        });
        foreach (['technical_state','diagnosis','bulk_candidate','statistics_origin','requires_review','lease_token','lease_until','attempts','preparation','row_summary','revised_result','previous_choice'] as $field) {
            $schema->table('replenishment_generation_items',fn (Blueprint $t) => $t->dropColumn($field));
        }
    }
}
