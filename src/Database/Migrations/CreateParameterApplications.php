<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Database\Migrations;

use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\{Blueprint,Builder};

/** Separa los intentos de aplicación externa de las revisiones aprobadas e inmutables. */
final class CreateParameterApplications extends Migration
{
    /** Obtiene el esquema autorizado. @return Builder Destino propio. @throws \LogicException Fuera del migrador. */
    private function schema(): Builder
    {
        $own=app(PersistenceConnection::class);
        if (!$own->isMigrating()) { throw new \LogicException('Use el migrador de Replenishment.'); }
        return $own->connection()->getSchemaBuilder();
    }

    /** Registra actor, fotografía, cambios autorizados y desenlace de cada intento. @return void */
    public function up(): void
    {
        $this->schema()->create('replenishment_parameter_applications',function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('review_id');
            $table->unsignedInteger('attempt');
            $table->string('actor_id',100);
            $table->string('status',16);
            $table->string('diagnosis',40)->nullable();
            $table->longText('expected');
            $table->longText('changes');
            $table->timestamps(6);
            $table->foreign('review_id','rep_pa_review')->references('id')->on('replenishment_parameter_reviews')->onDelete('no action');
            $table->unique(['review_id','attempt'],'rep_pa_attempt');
        });
    }

    /** Revierte exclusivamente el registro propio de aplicaciones. @return void */
    public function down(): void
    {
        $this->schema()->drop('replenishment_parameter_applications');
    }
}
