<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\Contracts\InventorySource;
use Asisnet\Replenishment\Contracts\InventoryMovementSource;
use Asisnet\Replenishment\Contracts\ProductSource;
use Asisnet\Replenishment\Contracts\PurchaseSource;
use Asisnet\Replenishment\Contracts\PurchaseHistorySource;
use Asisnet\Replenishment\Contracts\ReservationSource;
use Asisnet\Replenishment\Contracts\SaleSource;
use Asisnet\Replenishment\Contracts\SupplierSource;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

/**
 * Resuelve adaptadores explícitos del consumidor sin conocer su almacenamiento.
 */
final class AdapterRegistry
{
    /** @var array<string, class-string> Contratos admitidos para cada origen. */
    public const CONTRACTS = [
        'product_costs' => \Asisnet\Replenishment\Contracts\ProductCostSource::class,
        'products' => ProductSource::class,
        'inventory' => InventorySource::class,
        'movements' => InventoryMovementSource::class,
        'reservations' => ReservationSource::class,
        'commercial_history' => \Asisnet\Replenishment\Contracts\CommercialHistorySource::class,
        'sales' => SaleSource::class,
        'purchases' => PurchaseSource::class,
        'purchase_history' => PurchaseHistorySource::class,
        'suppliers' => SupplierSource::class,
    ];

    /**
     * Recibe infraestructura de Laravel sin consultar los orígenes de negocio.
     *
     * @param  Container  $container  Contenedor responsable de construir adaptadores.
     * @param  Repository  $config  Configuración de clases suministrada por el consumidor.
     */
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /**
     * Construye un adaptador sólo cuando el consumidor lo solicita.
     *
     * @param  string  $source  Clave de origen definida en CONTRACTS.
     * @return object Instancia que implementa el contrato correspondiente.
     *
     * @throws InvalidArgumentException Si el origen no existe.
     * @throws LogicException Si falta la configuración o la clase no implementa el contrato.
     * @throws \Illuminate\Contracts\Container\BindingResolutionException Si fallan sus dependencias.
     */
    public function resolve(string $source): object
    {
        $contract = self::CONTRACTS[$source] ?? null;

        if ($contract === null) {
            throw new InvalidArgumentException("Origen desconocido: {$source}.");
        }

        $class = $this->config->get("replenishment.adapters.{$source}");

        if (! is_string($class) || ! is_a($class, $contract, true)) {
            throw new LogicException("Configure un adaptador válido para {$source}: {$contract}.");
        }

        $adapter = $this->container->make($class);

        if (! $adapter instanceof $contract) {
            throw new LogicException("El contenedor devolvió un adaptador incompatible para {$source}.");
        }

        return $adapter;
    }
}
