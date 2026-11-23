<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Database\Migrations;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Añade caché diaria reconstruible y evidencia de ventas a métricas, conservando las tablas anteriores. */
final class AddObservedSales extends Migration
{
    /**
     * Crea agregados diarios y amplía métricas sin reinterpretar demanda proyectada.
     * @return void
     * @throws LogicException Si se omite el migrador propio.
     */
    public function up(): void
    {
        $schema = $this->schema();
        $schema->create('replenishment_daily_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 100); $table->string('location_id', 100); $table->date('sales_date');
            foreach (['gross_sales_units', 'returned_units', 'reversed_return_units', 'net_sales_units'] as $field) {
                $this->decimal($table, $field);
            }
            $table->integer('event_count')->nullable(); $table->string('base_unit', 100)->nullable();
            $table->string('observation_status', 20); $table->text('quality_issues'); $table->text('inventory_quality_issues');
            $table->string('observation_timezone', 100); $table->string('algorithm_version', 100);
            $table->dateTime('calculated_at', 6); $table->timestamps(6);
            $table->unique(['product_id', 'location_id', 'sales_date'], 'rep_daily_scope_date_u');
            $table->index(['sales_date', 'observation_status'], 'rep_daily_date_status_i');
        });
        $schema->table('replenishment_product_metrics', function (Blueprint $table): void {
            $this->decimal($table, 'reversed_returns');
            $table->text('observed_sales_summary')->nullable();
        });
    }

    /**
     * Revierte sólo la ampliación, preservando el esquema anterior.
     * @return void
     * @throws LogicException Si se omite el migrador propio.
     */
    public function down(): void
    {
        $schema = $this->schema();
        $schema->table('replenishment_product_metrics', function (Blueprint $table): void {
            $table->dropColumn(['reversed_returns', 'observed_sales_summary']);
        });
        $schema->dropIfExists('replenishment_daily_sales');
    }

    /**
     * Resuelve esquema y registro en el destino propio.
     * @return Builder Esquema aislado.
     * @throws LogicException Si se ejecuta fuera del contexto autorizado.
     */
    private function schema(): Builder
    {
        $connection = app(PersistenceConnection::class);
        if (!$connection->isMigrating()) {
            throw new LogicException('Utilice replenishment:migrate para mantener el aislamiento de tablas y registro.');
        }
        return $connection->connection()->getSchemaBuilder();
    }

    /**
     * Declara decimal exacto o texto en SQLite.
     * @param Blueprint $table Tabla.
     * @param string $name Campo nullable.
     * @return void
     */
    private function decimal(Blueprint $table, string $name): void
    {
        ($this->schema()->getConnection()->getDriverName() === 'sqlite' ? $table->text($name) : $table->decimal($name, 24, 6))->nullable();
    }
}

