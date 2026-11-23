<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use LogicException;

/**
 * Ejecuta sólo migraciones propias con un registro dedicado en la misma conexión.
 */
final class PackageMigrator
{
    /**
     * Recibe infraestructura sin reemplazar el migrador global de Artisan.
     *
     * @param PersistenceConnection $connection Resolución de persistencia propia.
     * @param Filesystem $files Lector de archivos de migración.
     * @param Dispatcher $events Eventos estándar de migración.
     */
    public function __construct(
        private PersistenceConnection $connection,
        private Filesystem $files,
        private Dispatcher $events,
    ) {}

    /**
     * Aplica pendientes o revierte el último lote exclusivamente del paquete.
     *
     * @param bool $rollback Indica si se solicita reversión del último lote propio.
     * @return void
     * @throws LogicException Si hay tablas previas sin un registro propio conciliado.
     * @throws \Throwable Si falla la validación, conexión o ejecución del DDL.
     */
    public function run(bool $rollback = false): void
    {
        $this->connection->migrating(function () use ($rollback): void {
            // No adopta una instalación del migrador estándar.
            $db=$this->connection->connection();
            $table=config('database.migrations','migrations');
            $table=is_array($table)?$table['table']:$table;
            if($db->getSchemaBuilder()->hasTable($table)&&$db->table($table)->where('migration','like','%replenishment_standard%')->exists()){
                throw new LogicException('Esta instalación contiene migraciones estándar de Replenishment. Use migrate con su conexión; no se adoptará el historial.');
            }
            $repository = new DatabaseMigrationRepository($this->connection, 'replenishment_migrations');
            $repository->setSource($this->connection->getDefaultConnection());
            $migrator = new Migrator($repository, $this->connection, $this->files, $this->events);
            $migrator->setConnection($this->connection->getDefaultConnection());

            if (!$repository->repositoryExists()) {
                if ($this->connection->connection()->getSchemaBuilder()->hasTable('replenishment_purchase_units')) {
                    throw new LogicException('Existen tablas de Replenishment sin su registro dedicado. Concilie las migraciones anteriores antes de continuar; no se adoptarán ni eliminarán automáticamente.');
                }
                if ($rollback) {
                    return;
                }
                $repository->createRepository();
            }

            $path = __DIR__.'/../../database/migrations';
            if ($rollback) {
                $migrator->rollback([$path]);
            } else {
                $migrator->run([$path]);
            }
        });
    }
}
