<?php

declare(strict_types=1);

namespace Asisnet\Replenishment;

use Asisnet\Replenishment\Commands\StatusCommand;
use Asisnet\Replenishment\Commands\MigrateCommand;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Asisnet\Replenishment\Services\AdapterRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Integra la configuración, el registro de adaptadores y los comandos sin acceder a datos.
 */
final class ReplenishmentServiceProvider extends ServiceProvider
{
    /**
     * Registra configuración, adaptadores diferidos y el resolver exclusivo de persistencia.
     *
     * No instancia adaptadores ni ejecuta migraciones o tareas programadas.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/replenishment.php', 'replenishment');
        $this->app->scoped(\Asisnet\Replenishment\Services\PerformanceTrace::class);
        $this->app->singleton(AdapterRegistry::class);
        $this->app->singleton(PersistenceConnection::class);
    }

    /**
     * Publica configuración y comandos explícitos, sin registrar rutas en migrate global.
     */
    public function boot(): void
    {
        if (config('replenishment.performance_log')) {
            $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class,
                fn ($event) => app(\Asisnet\Replenishment\Services\PerformanceTrace::class)->query($event));
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/replenishment.php' => config_path('replenishment.php'),
            ], 'replenishment-config');

            $this->publishes([__DIR__.'/../database/published' => database_path('migrations/replenishment')], 'replenishment-migrations');
            $this->publishes([__DIR__.'/../database/erp-published' => database_path('migrations/replenishment-erp')], 'replenishment-statistics-cutoff-migration');
            $this->commands([StatusCommand::class, MigrateCommand::class]);
        }
    }
}
