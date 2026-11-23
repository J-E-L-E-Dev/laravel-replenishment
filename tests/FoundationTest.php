<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\Contracts\ProductSource;
use Asisnet\Replenishment\DTO\ProductData;
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Asisnet\Replenishment\Services\AdapterRegistry;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use Orchestra\Testbench\TestCase;

/**
 * Verifica la integración del proveedor y los límites del registro sin base de datos.
 */
final class FoundationTest extends TestCase
{
    /**
     * Carga el proveedor en la aplicación aislada de Testbench.
     *
     * @param  \Illuminate\Foundation\Application  $app  Aplicación temporal de pruebas.
     * @return array<class-string> Proveedores bajo prueba.
     */
    protected function getPackageProviders($app): array
    {
        return [ReplenishmentServiceProvider::class];
    }

    /**
     * Comprueba los ocho puntos de integración inicialmente pendientes.
     */
    public function testDefaultAdaptersAreUnconfigured(): void
    {
        self::assertSame(
            array_fill_keys(array_keys(AdapterRegistry::CONTRACTS), null),
            $this->app['config']->get('replenishment.adapters'),
        );
        self::assertSame(
            $this->app->make(AdapterRegistry::class),
            $this->app->make(AdapterRegistry::class),
        );
    }

    /**
     * Verifica que el diagnóstico funcione sin adaptadores ni conexiones.
     */
    public function testStatusCommandRunsWithoutAdapters(): void
    {
        $this->artisan('replenishment:status')
            ->expectsOutputToContain('motor no implementado')
            ->expectsOutputToContain('products: sin configurar')
            ->assertSuccessful();
    }

    /**
     * Verifica etiquetas, destinos separados y ausencia de registro en el migrador general.
     * @return void
     */
    public function testPublicationIsSeparatedAndErpMigrationIsOptIn(): void
    {
        $groups = [
            'replenishment-config' => ['config/replenishment.php', config_path('replenishment.php')],
            'replenishment-migrations' => ['database/published', database_path('migrations/replenishment')],
            'replenishment-statistics-cutoff-migration' => ['database/erp-published', database_path('migrations/replenishment-erp')],
        ];
        foreach ($groups as $tag => [$source, $target]) {
            $paths = ServiceProvider::pathsToPublish(ReplenishmentServiceProvider::class, $tag);
            self::assertSame([$target], array_values($paths));
            self::assertSame(realpath(__DIR__.'/../'.$source), realpath(array_key_first($paths)));
        }
        self::assertFalse(config('replenishment.statistics_cutoff.enabled'));
        self::assertNotContains(realpath(__DIR__.'/../database/erp-published'), array_map('realpath', app('migrator')->paths()));
        self::assertSame([], glob(__DIR__.'/../database/migrations/*cutoff*'));
    }

    /**
     * Comprueba la resolución diferida de una implementación del consumidor.
     */
    public function testConfiguredAdapterResolvesThroughContainer(): void
    {
        $adapter = $this->createMock(ProductSource::class);
        $adapter->expects(self::once())->method('find')->with('0001')
            ->willReturn(new ProductData('0001', 'Producto', 'unidad'));
        $this->app->instance(get_class($adapter), $adapter);
        $this->app['config']->set('replenishment.adapters.products', get_class($adapter));

        $resolved = $this->app->make(AdapterRegistry::class)->resolve('products');

        self::assertSame($adapter, $resolved);
        self::assertSame('0001', $resolved->find('0001')->id);
    }

    /**
     * Impide interpretar la falta de adaptador como datos vacíos de negocio.
     */
    public function testMissingAdapterFailsExplicitly(): void
    {
        $this->expectException(LogicException::class);
        $this->app->make(AdapterRegistry::class)->resolve('inventory');
    }

    /**
     * Rechaza una clase que no cumpla el contrato del origen.
     */
    public function testIncompatibleAdapterIsRejected(): void
    {
        $this->app['config']->set('replenishment.adapters.products', \stdClass::class);
        $this->expectException(LogicException::class);
        $this->app->make(AdapterRegistry::class)->resolve('products');
    }

    /**
     * Rechaza claves desconocidas antes de intentar resolver dependencias.
     */
    public function testUnknownSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(AdapterRegistry::class)->resolve('unknown');
    }

    /**
     * Valida también la instancia devuelta por un binding del consumidor.
     */
    public function testContainerCannotReturnAnIncompatibleInstance(): void
    {
        $adapter = $this->createMock(ProductSource::class);
        $this->app['config']->set('replenishment.adapters.products', get_class($adapter));
        $this->app->instance(get_class($adapter), new \stdClass);
        $this->expectException(LogicException::class);
        $this->app->make(AdapterRegistry::class)->resolve('products');
    }

    /**
     * Comprueba que una configuración propia no sea reemplazada por los valores base.
     */
    public function testConsumerConfigurationIsPreserved(): void
    {
        $this->app['config']->set('replenishment.adapters.products', 'ConsumerAdapter');
        (new ReplenishmentServiceProvider($this->app))->register();
        self::assertSame('ConsumerAdapter', $this->app['config']->get('replenishment.adapters.products'));
    }

    /**
     * Comprueba que el diagnóstico no construya una clase configurada inexistente.
     */
    public function testStatusDoesNotInstantiateAdapters(): void
    {
        $this->app['config']->set('replenishment.adapters.products', 'ConsumerAdapter');
        $this->artisan('replenishment:status')
            ->expectsOutputToContain('products: ConsumerAdapter')
            ->assertSuccessful();
    }
}


