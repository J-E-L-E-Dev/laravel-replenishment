<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Database\Migrations;

use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Añade estadísticas por ejecución y unidad en tablas independientes de la caché histórica. */
final class CreateCommercialStatistics extends Migration
{
    /** Obtiene exclusivamente el esquema autorizado. @return Builder Esquema propio. @throws LogicException Fuera del migrador explícito. */
    private function schema(): Builder
    {
        $own = app(PersistenceConnection::class);
        if (!$own->isMigrating()) {
            throw new LogicException('Use el migrador explícito de Replenishment.');
        }
        return $own->connection()->getSchemaBuilder();
    }

    /** Crea cabeceras inmutables, cobertura diaria y componentes por unidad. @return void */
    public function up(): void
    {
        $schema = $this->schema();
        $sqlite = app(PersistenceConnection::class)->connection()->getDriverName() === 'sqlite';
        $schema->create('replenishment_sales_runs', function (Blueprint $t): void {
            $t->id();
            $t->string('product_id', 100);
            $t->string('location_id', 100);
            $t->char('profile_hash', 64);
            $t->char('scope_hash', 64);
            $t->text('profile');
            $t->string('status', 20);
            $t->date('requested_start');
            $t->date('effective_start');
            $t->date('until_date');
            $t->dateTime('calculated_at', 6);
            $t->index(['profile_hash','requested_start','until_date'], 'rep_sr_interval');
            $t->index(['product_id','location_id','scope_hash','calculated_at'], 'rep_sr_scope');
        });
        $schema->create('replenishment_sales_days', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('run_id');
            $t->foreign('run_id','rep_sd_run')->references('id')->on('replenishment_sales_runs')->onDelete('no action');
            $t->date('sales_date');
            $t->string('state', 24);
            $t->unsignedInteger('event_count')->nullable();
            $t->text('issues');
            $t->unique(['run_id','sales_date'], 'rep_sd_date');
        });
        $schema->create('replenishment_sales_unit_totals', function (Blueprint $t) use ($sqlite): void {
            $t->id();
            $t->unsignedBigInteger('day_id');
            $t->foreign('day_id','rep_su_day')->references('id')->on('replenishment_sales_days')->onDelete('no action');
            $t->string('unit', 100);
            $t->char('unit_hash', 64);
            $t->unsignedInteger('event_count');
            foreach (['gross_sales_units','returned_units','reversed_return_units','net_sales_units'] as $field) {
                if ($sqlite) {
                    $t->text($field);
                } else {
                    $t->decimal($field, 24, 6);
                }
            }
            $t->unique(['day_id','unit_hash'], 'rep_su_unit');
        });
    }

    /** Revierte exclusivamente las tablas de esta evolución, de hijas a padre. @return void */
    public function down(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists('replenishment_sales_unit_totals');
        $schema->dropIfExists('replenishment_sales_days');
        $schema->dropIfExists('replenishment_sales_runs');
    }
}
