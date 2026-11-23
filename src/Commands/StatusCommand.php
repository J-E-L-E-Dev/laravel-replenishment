<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Commands;

use Asisnet\Replenishment\Services\AdapterRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Informa la configuración de la fundación sin instanciar adaptadores ni consultar datos.
 */
final class StatusCommand extends Command
{
    /** @var string Nombre público del comando de diagnóstico. */
    protected $signature = 'replenishment:status';

    /** @var string Descripción visible en la ayuda de Artisan. */
    protected $description = 'Muestra la configuración de los adaptadores de reposición.';

    /**
     * Lista las clases configuradas; no certifica conectividad ni validez de datos.
     *
     * @param  Repository  $config  Configuración actual del consumidor.
     * @return int Código de éxito del diagnóstico informativo.
     */
    public function handle(Repository $config): int
    {
        $this->info('Laravel Replenishment — fundación; motor no implementado.');

        foreach (AdapterRegistry::CONTRACTS as $source => $contract) {
            $class = $config->get("replenishment.adapters.{$source}");
            $status = is_string($class) && $class !== '' ? $class : 'sin configurar';
            $this->line("{$source}: {$status}");
        }

        return self::SUCCESS;
    }
}
