<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Asisnet\Replenishment\Services\PersistenceConnection;
use LogicException;

/**
 * Crea exclusivamente las doce tablas del paquete con relaciones internas sin cascadas.
 *
 * SQLite conserva decimales como texto; los demás motores usan DECIMAL explícito.
 */
final class CreateReplenishmentTables extends Migration
{
    /**
     * Crea configuraciones, históricos y propuestas en orden de dependencia.
     *
     * @throws LogicException Si no se invoca mediante el migrador explícito del paquete.
     * @throws \Illuminate\Database\QueryException Si el motor rechaza el esquema.
     */
    public function up(): void
    {
        $schema = $this->schema();

        $schema->create('replenishment_purchase_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps(6);
            $table->unique(['code'], 'rep_1_u1');
        });

        $schema->create('replenishment_product_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 100);
            $table->string('supplier_id', 100);
            $table->boolean('is_preferred')->default(false);
            $table->boolean('active')->default(true);
            $table->integer('manual_lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unique(['product_id', 'supplier_id'], 'rep_2_u1');
            $table->index(['supplier_id', 'active'], 'rep_2_i1');
        });

        $schema->create('replenishment_supplier_presentations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_supplier_id');
            $table->foreign('product_supplier_id', 'rep_3_product_supplier_id_fk')
                ->references('id')->on('replenishment_product_suppliers')->onDelete('no action');
            $table->unsignedBigInteger('purchase_unit_id');
            $table->foreign('purchase_unit_id', 'rep_3_purchase_unit_id_fk')
                ->references('id')->on('replenishment_purchase_units')->onDelete('no action');
            $table->integer('revision')->default(1);
            $this->decimal($table, 'base_units_per_package', 24, 6, false);
            $this->decimal($table, 'minimum_packages', 24, 6, false);
            $this->decimal($table, 'package_multiple', 24, 6, false);
            $table->boolean('active')->default(true);
            $table->timestamps(6);
            $table->unique(['product_supplier_id', 'purchase_unit_id', 'revision'], 'rep_3_u1');
            $table->index(['purchase_unit_id'], 'rep_3_i1');
        });

        $schema->create('replenishment_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->integer('analysis_days')->nullable();
            $table->integer('target_coverage_days')->nullable();
            $this->decimal($table, 'service_level', 9, 6, true);
            $table->integer('default_lead_time_days')->nullable();
            $table->integer('minimum_history_days')->nullable();
            $table->integer('seasonal_minimum_years')->nullable();
            $table->integer('forecast_horizon_days')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps(6);
        });

        $schema->create('replenishment_product_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 100);
            $table->string('location_id', 100)->nullable();
            $table->dateTime('calculated_at', 6);
            $table->dateTime('analysis_start', 6);
            $table->dateTime('analysis_end', 6);
            $table->string('algorithm_version', 100);
            $table->integer('observed_days')->nullable();
            $table->integer('available_days')->nullable();
            $table->integer('stockout_days')->nullable();
            $this->decimal($table, 'gross_sales', 24, 6, true);
            $this->decimal($table, 'returns', 24, 6, true);
            $this->decimal($table, 'net_sales', 24, 6, true);
            $this->decimal($table, 'demand_30_days', 24, 6, true);
            $this->decimal($table, 'demand_60_days', 24, 6, true);
            $this->decimal($table, 'demand_90_days', 24, 6, true);
            $this->decimal($table, 'demand_180_days', 24, 6, true);
            $this->decimal($table, 'demand_365_days', 24, 6, true);
            $this->decimal($table, 'daily_demand', 24, 6, true);
            $this->decimal($table, 'weighted_daily_demand', 24, 6, true);
            $this->decimal($table, 'variability', 24, 6, true);
            $this->decimal($table, 'trend', 24, 6, true);
            $table->string('rotation_class', 30)->nullable();
            $table->string('abc_class', 10)->nullable();
            $this->decimal($table, 'on_hand', 24, 6, true);
            $this->decimal($table, 'fixed_reserved', 24, 6, true);
            $this->decimal($table, 'manual_reserved', 24, 6, true);
            $this->decimal($table, 'quotation_reserved', 24, 6, true);
            $this->decimal($table, 'available_stock', 24, 6, true);
            $this->decimal($table, 'recommended_safety_stock', 24, 6, true);
            $this->decimal($table, 'reorder_point', 24, 6, true);
            $this->decimal($table, 'recommended_minimum', 24, 6, true);
            $this->decimal($table, 'recommended_maximum', 24, 6, true);
            $this->decimal($table, 'coverage_days', 24, 6, true);
            $this->decimal($table, 'quality', 9, 6, true);
            $this->decimal($table, 'confidence', 9, 6, true);
            $table->timestamps(6);
            $table->index(['product_id', 'location_id', 'calculated_at'], 'rep_5_i1');
            $table->index(['calculated_at'], 'rep_5_i2');
        });

        $schema->create('replenishment_supplier_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_supplier_id');
            $table->foreign('product_supplier_id', 'rep_6_product_supplier_id_fk')
                ->references('id')->on('replenishment_product_suppliers')->onDelete('no action');
            $table->dateTime('analysis_start', 6);
            $table->dateTime('analysis_end', 6);
            $table->dateTime('calculated_at', 6);
            $table->string('algorithm_version', 100);
            $this->decimal($table, 'average_lead_time_days', 24, 6, true);
            $this->decimal($table, 'minimum_lead_time_days', 24, 6, true);
            $this->decimal($table, 'maximum_lead_time_days', 24, 6, true);
            $this->decimal($table, 'lead_time_deviation', 24, 6, true);
            $table->string('estimation_source', 100)->nullable();
            $table->integer('verified_orders')->nullable();
            $table->integer('partial_receipts')->nullable();
            $this->decimal($table, 'quantity_fulfillment', 9, 6, true);
            $this->decimal($table, 'date_fulfillment', 9, 6, true);
            $this->decimal($table, 'confidence', 9, 6, true);
            $table->timestamps(6);
            $table->index(['product_supplier_id', 'calculated_at'], 'rep_6_i1');
        });

        $schema->create('replenishment_seasonal_patterns', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 100);
            $table->string('location_id', 100)->nullable();
            $table->string('period_type', 10);
            $table->integer('period_number');
            $table->integer('historical_years')->nullable();
            $this->decimal($table, 'seasonal_index', 24, 6, true);
            $this->decimal($table, 'mean_demand', 24, 6, true);
            $table->string('period_class', 30)->nullable();
            $this->decimal($table, 'confidence', 9, 6, true);
            $table->dateTime('calculated_at', 6);
            $table->string('algorithm_version', 100);
            $table->timestamps(6);
            $table->index(['product_id', 'location_id', 'period_type', 'period_number'], 'rep_7_i1');
            $table->index(['calculated_at'], 'rep_7_i2');
        });

        $schema->create('replenishment_demand_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 100);
            $table->string('location_id', 100)->nullable();
            $table->dateTime('generated_at', 6);
            $table->dateTime('period_start', 6);
            $table->dateTime('period_end', 6);
            $table->string('granularity', 20);
            $this->decimal($table, 'forecast_quantity', 24, 6, true);
            $this->decimal($table, 'lower_bound', 24, 6, true);
            $this->decimal($table, 'upper_bound', 24, 6, true);
            $this->decimal($table, 'base_demand', 24, 6, true);
            $this->decimal($table, 'seasonal_index', 24, 6, true);
            $this->decimal($table, 'trend_factor', 24, 6, true);
            $table->string('method', 100);
            $table->string('algorithm_version', 100);
            $this->decimal($table, 'confidence', 9, 6, true);
            $table->timestamps(6);
            $table->index(['product_id', 'location_id', 'period_start'], 'rep_8_i1');
            $table->index(['generated_at'], 'rep_8_i2');
        });

        $schema->create('replenishment_runs', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->string('requested_by', 100)->nullable();
            $table->dateTime('analysis_start', 6);
            $table->dateTime('analysis_end', 6);
            $table->string('algorithm_version', 100);
            $table->string('status', 20)->default('pending');
            $table->text('parameters_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps(6);
            $table->index(['status', 'created_at'], 'rep_9_i1');
        });

        $schema->create('replenishment_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('replenishment_run_id');
            $table->foreign('replenishment_run_id', 'rep_10_replenishment_run_id_fk')
                ->references('id')->on('replenishment_runs')->onDelete('no action');
            $table->string('product_id', 100);
            $table->string('location_id', 100)->nullable();
            $table->string('supplier_id', 100)->nullable();
            $table->unsignedBigInteger('supplier_presentation_id')->nullable();
            $table->foreign('supplier_presentation_id', 'rep_10_supplier_presentation_id_fk')
                ->references('id')->on('replenishment_supplier_presentations')->onDelete('no action');
            $table->text('presentation_snapshot')->nullable();
            $this->decimal($table, 'available_stock', 24, 6, true);
            $this->decimal($table, 'verified_incoming', 24, 6, true);
            $this->decimal($table, 'inventory_position', 24, 6, true);
            $this->decimal($table, 'lead_time_demand', 24, 6, true);
            $this->decimal($table, 'safety_stock', 24, 6, true);
            $this->decimal($table, 'reorder_point', 24, 6, true);
            $this->decimal($table, 'target_stock', 24, 6, true);
            $this->decimal($table, 'required_base_units', 24, 6, true);
            $this->decimal($table, 'suggested_packages', 24, 6, true);
            $this->decimal($table, 'suggested_base_units', 24, 6, true);
            $this->decimal($table, 'projected_coverage_days', 24, 6, true);
            $table->date('suggested_order_date')->nullable();
            $table->date('expected_receipt_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('explanation')->nullable();
            $table->timestamps(6);
            $table->index(['replenishment_run_id', 'product_id', 'location_id'], 'rep_10_i1');
            $table->index(['supplier_presentation_id'], 'rep_10_i2');
            $table->index(['supplier_id', 'status'], 'rep_10_i3');
        });

        $schema->create('replenishment_purchase_proposals', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier_id', 100);
            $table->string('location_id', 100)->nullable();
            $table->unsignedBigInteger('replenishment_run_id')->nullable();
            $table->foreign('replenishment_run_id', 'rep_11_replenishment_run_id_fk')
                ->references('id')->on('replenishment_runs')->onDelete('no action');
            $table->string('status', 20)->default('draft');
            $table->string('created_by', 100)->nullable();
            $table->string('reviewed_by', 100)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->index(['supplier_id', 'location_id', 'created_at'], 'rep_11_i1');
            $table->index(['status', 'created_at'], 'rep_11_i2');
            $table->index(['replenishment_run_id'], 'rep_11_i3');
        });

        $schema->create('replenishment_purchase_proposal_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_proposal_id');
            $table->foreign('purchase_proposal_id', 'rep_12_purchase_proposal_id_fk')
                ->references('id')->on('replenishment_purchase_proposals')->onDelete('no action');
            $table->string('product_id', 100);
            $table->unsignedBigInteger('supplier_presentation_id')->nullable();
            $table->foreign('supplier_presentation_id', 'rep_12_supplier_presentation_id_fk')
                ->references('id')->on('replenishment_supplier_presentations')->onDelete('no action');
            $table->text('presentation_snapshot')->nullable();
            $this->decimal($table, 'suggested_base_units', 24, 6, false);
            $this->decimal($table, 'suggested_packages', 24, 6, true);
            $this->decimal($table, 'approved_base_units', 24, 6, true);
            $this->decimal($table, 'approved_packages', 24, 6, true);
            $this->decimal($table, 'estimated_unit_cost', 24, 6, true);
            $table->string('currency_code', 3)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->timestamps(6);
            $table->index(['purchase_proposal_id'], 'rep_12_i1');
            $table->index(['supplier_presentation_id'], 'rep_12_i2');
            $table->index(['product_id'], 'rep_12_i3');
        });
    }

    /**
     * Revierte exclusivamente tablas propias en orden inverso de dependencia.
     *
     * Sólo debe ejecutarse en bases temporales o después de respaldar sus históricos.
     *
     * @throws LogicException Si no se invoca mediante el migrador explícito del paquete.
     * @throws \Illuminate\Database\QueryException Si existen referencias ajenas al paquete.
     */
    public function down(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists('replenishment_purchase_proposal_items');
        $schema->dropIfExists('replenishment_purchase_proposals');
        $schema->dropIfExists('replenishment_results');
        $schema->dropIfExists('replenishment_runs');
        $schema->dropIfExists('replenishment_demand_forecasts');
        $schema->dropIfExists('replenishment_seasonal_patterns');
        $schema->dropIfExists('replenishment_supplier_metrics');
        $schema->dropIfExists('replenishment_product_metrics');
        $schema->dropIfExists('replenishment_policies');
        $schema->dropIfExists('replenishment_supplier_presentations');
        $schema->dropIfExists('replenishment_product_suppliers');
        $schema->dropIfExists('replenishment_purchase_units');
    }

    /**
     * Exige el migrador explícito y usa su mismo destino para todo el DDL.
     *
     * @return Builder Constructor de esquema en la conexión elegida.
     * @throws LogicException Si se intenta ejecutar desde migrate o rollback generales.
     */
    private function schema(): Builder
    {
        $connection = app(PersistenceConnection::class);
        if (!$connection->isMigrating()) {
            throw new LogicException('Utilice replenishment:migrate o replenishment:migrate --rollback para mantener tablas y registro en la misma conexión.');
        }

        return $connection->connection()->getSchemaBuilder();
    }

    /**
     * Declara cantidades exactas sin la afinidad numérica aproximada de SQLite.
     *
     * @param  Blueprint  $table  Tabla en construcción.
     * @param  string  $name  Nombre de columna.
     * @param  int  $precision  Dígitos totales admitidos.
     * @param  int  $scale  Dígitos fraccionarios.
     * @param  bool  $nullable  Indica si el dato puede ser desconocido.
     */
    private function decimal(Blueprint $table, string $name, int $precision, int $scale, bool $nullable): void
    {
        $column = $this->schema()->getConnection()->getDriverName() === 'sqlite'
            ? $table->text($name)
            : $table->decimal($name, $precision, $scale);

        if ($nullable) {
            $column->nullable();
        }
    }
}
