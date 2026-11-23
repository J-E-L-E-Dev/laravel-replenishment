<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Commands;

use Asisnet\Replenishment\Services\PackageMigrator;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use LogicException;

/**
 * Migra o revierte el paquete sin aceptar destinos alternativos ni rutas arbitrarias.
 */
final class MigrateCommand extends Command
{
    use ConfirmableTrait;

    /** @var string Operaciones explícitas sobre el último lote propio. */
    protected $signature = 'replenishment:migrate {--rollback : Revierte el último lote del paquete} {--force : Permite ejecutar en producción}';

    /** @var string Descripción pública del alcance del comando. */
    protected $description = 'Migra la persistencia propia de Replenishment en su conexión configurada.';

    /**
     * Valida el destino y delega al migrador aislado, mostrando errores de configuración.
     *
     * @param PersistenceConnection $connection Destino común para tablas y registro.
     * @param PackageMigrator $migrator Migrador exclusivo del paquete.
     * @return int Código de éxito o fallo.
     * @throws \Throwable Propaga fallos operativos del controlador de base de datos.
     */
    public function handle(PersistenceConnection $connection, PackageMigrator $migrator): int
    {
        try {
            $name = $connection->getDefaultConnection();
            if (!$this->confirmToProceed('Se modificarán las tablas de Replenishment.')) {
                return self::FAILURE;
            }
            $migrator->run((bool) $this->option('rollback'));
            $this->info("Operación de Replenishment completada en la conexión '{$name}'.");

            return self::SUCCESS;
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
