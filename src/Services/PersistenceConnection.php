<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Resuelve únicamente la persistencia del paquete sin modificar conexiones del ERP.
 */
final class PersistenceConnection implements ConnectionResolverInterface
{
    /** @var string|null Destino fijado durante una migración para evitar cambios de contexto. */
    private ?string $migrationConnection = null;

    /**
     * Recibe configuración y conexiones existentes; nunca crea configuraciones nuevas.
     *
     * @param Repository $config Configuración de Laravel.
     * @param DatabaseManager $database Administrador de conexiones existentes.
     */
    public function __construct(private Repository $config, private DatabaseManager $database) {}

    /**
     * Obtiene y valida el nombre Laravel, que no tiene por qué coincidir con el driver.
     *
     * @return string Nombre configurado o predeterminado.
     * @throws InvalidArgumentException Si el nombre no corresponde a una conexión definida.
     */
    public function getDefaultConnection()
    {
        $name = $this->migrationConnection ?? $this->config->get('replenishment.connection');
        if ($name === null || $name === '') {
            $name = $this->config->get('database.default');
        }
        $connections = $this->config->get('database.connections', []);

        if (!is_string($name) || $name === '' || !is_array($connections)
            || !isset($connections[$name]) || !is_array($connections[$name])) {
            throw new InvalidArgumentException(
                'La conexión de persistencia de Replenishment no está definida en database.connections. Revise REPLENISHMENT_DB_CONNECTION y database.default.'
            );
        }

        return $name;
    }

    /**
     * Resuelve la conexión propia e impide redirigir modelos o migraciones al ERP.
     *
     * @param string|null $name Nombre solicitado por Laravel.
     * @return Connection Conexión del paquete.
     * @throws InvalidArgumentException Si se solicita un destino distinto o inexistente.
     */
    public function connection($name = null)
    {
        $this->assertConnection($name);

        return $this->database->connection($this->getDefaultConnection());
    }

    /**
     * Comprueba una asignación de conexión sin abrir PDO.
     *
     * @param string|null $name Nombre explícito o null para el destino del paquete.
     * @return void
     * @throws InvalidArgumentException Si el destino contradice la configuración del paquete.
     */
    public function assertConnection($name): void
    {
        $configured = $this->getDefaultConnection();
        if ($name !== null && $name !== $configured) {
            throw new InvalidArgumentException("La persistencia de Replenishment utiliza '{$configured}'; no puede redirigirse a otra conexión.");
        }
    }

    /**
     * Satisface al migrador sin alterar el valor predeterminado global de Laravel.
     *
     * @param string $name Destino que el migrador pretende utilizar.
     * @return void
     * @throws InvalidArgumentException Si el destino es distinto del configurado.
     */
    public function setDefaultConnection($name)
    {
        $this->assertConnection($name);
    }

    /**
     * Ejecuta futuras transacciones propias en la misma conexión que los modelos.
     *
     * @param Closure $callback Operación transaccional; recibe la conexión utilizada.
     * @param int $attempts Intentos de transacción admitidos por Laravel.
     * @return mixed Resultado de la operación.
     * @throws \Throwable Si la operación falla después de revertir la transacción.
     */
    public function transaction(Closure $callback, int $attempts = 1)
    {
        return $this->connection()->transaction($callback, $attempts);
    }

    /**
     * Delimita el contexto interno autorizado para ejecutar las migraciones propias.
     *
     * @internal Utilizado por PackageMigrator, PublishedMigration y la revisión aislada del DDL.
     * @param Closure $callback Operación de migración.
     * @return mixed Resultado de la operación.
     * @throws \Throwable Propaga el error y siempre restaura el contexto anterior.
     */
    public function migrating(Closure $callback)
    {
        $previous = $this->migrationConnection;
        $this->migrationConnection = $this->getDefaultConnection();
        try {
            return $callback();
        } finally {
            $this->migrationConnection = $previous;
        }
    }

    /**
     * Indica si la ejecución pertenece a un contexto explícito de migración autorizado.
     *
     * @return bool Contexto de migración activo.
     */
    public function isMigrating(): bool
    {
        return $this->migrationConnection !== null;
    }
}
