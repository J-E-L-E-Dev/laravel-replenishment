<?php

declare(strict_types=1);
use Asisnet\Replenishment\Services\StatisticsCutoff;
use Illuminate\Database\Migrations\Migration;

/** Extensión ERP optativa, separada del migrador propio y de publicación manual. */
return new class extends Migration
{
    /** Agrega DATE NULL sólo al destino explícito validado; no sobrescribe columnas. @return void @throws \Throwable Ante configuración o metadatos incompatibles. */
    public function up(): void {app(StatisticsCutoff::class)->install();}
    /** Impide eliminar datos o apropiarse de una columna externa, aunque cambie la configuración. @return void @throws LogicException Siempre: requiere revisión y reversión manual del destino original. */
    public function down(): void {throw new LogicException('El corte estadístico no admite rollback automático. Conserve la columna y revise manualmente el destino original; no se ha eliminado ningún dato.');}
};
