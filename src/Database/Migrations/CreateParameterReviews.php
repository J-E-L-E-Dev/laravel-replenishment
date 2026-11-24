<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Database\Migrations;

use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\{Blueprint,Builder};

/** Añade revisiones auditables independientes de las decisiones de compra. */
final class CreateParameterReviews extends Migration
{
    /** Obtiene el esquema autorizado. @return Builder Esquema propio. @throws \LogicException Fuera del migrador. */
    private function schema(): Builder
    {
        $own=app(PersistenceConnection::class);
        if (!$own->isMigrating()) { throw new \LogicException('Use el migrador de Replenishment.'); }
        return $own->connection()->getSchemaBuilder();
    }

    /** Crea una revisión inmutable por línea y versión. @return void */
    public function up(): void
    {
        $this->schema()->create('replenishment_parameter_reviews',function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('revision');
            $table->string('actor_id',100);
            $table->string('status',32);
            $table->longText('snapshot');
            $table->longText('decisions');
            $table->text('reason')->nullable();
            $table->timestamps(6);
            $table->unique(['item_id','revision'],'rep_parameter_version');
            $table->foreign('item_id','rep_parameter_item')->references('id')->on('replenishment_generation_items')->onDelete('no action');
        });
    }

    /** Revierte únicamente la tabla nueva en el destino propio. @return void */
    public function down(): void
    {
        $this->schema()->drop('replenishment_parameter_reviews');
    }
}
