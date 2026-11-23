<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Database\Migrations;

use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Amplía políticas existentes sin modificar sus parámetros o evidencias anteriores. */
final class AddPolicyDemandSettings extends Migration
{
    /** Obtiene el esquema propio durante la migración autorizada. @return Builder Esquema. @throws LogicException Fuera del migrador. */
    private function schema(): Builder
    {
        $own = app(PersistenceConnection::class);
        if (!$own->isMigrating()) {
            throw new LogicException('Use el migrador explícito de Replenishment.');
        }
        return $own->connection()->getSchemaBuilder();
    }

    /** Añade parámetros opcionales y la procedencia del consentimiento. @return void */
    public function up(): void
    {
        $this->schema()->table('replenishment_policies', function (Blueprint $table): void {
            $table->text('demand_settings')->nullable();
            $table->string('continuity_authorized_by', 100)->nullable();
        });
    }

    /** Retira sólo los campos de esta ampliación. @return void */
    public function down(): void
    {
        foreach (['continuity_authorized_by', 'demand_settings'] as $field) {
            $this->schema()->table('replenishment_policies', fn (Blueprint $table) => $table->dropColumn($field));
        }
    }
}
