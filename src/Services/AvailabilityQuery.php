<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\AvailabilityData;

/**
 * Coordina contratos de lectura sin utilizar conexiones ni modelos de persistencia propia.
 */
final class AvailabilityQuery
{
    /**
     * Recibe el registro existente y el cálculo sobre datos normalizados.
     *
     * @param AdapterRegistry $adapters Adaptadores configurados por el consumidor.
     * @param AvailabilityCalculator $calculator Calculador independiente del ERP.
     */
    public function __construct(
        private AdapterRegistry $adapters,
        private AvailabilityCalculator $calculator,
    ) {}

    /**
     * Consulta un producto y calcula su disponibilidad en un alcance explícito.
     *
     * @param string $productId Identificador opaco solicitado.
     * @param string $locationId Ubicación lógica reconocida por los adaptadores.
     * @return AvailabilityData|null Desglose, o null si el producto no existe.
     * @throws \RuntimeException Si un origen no puede leerse o normalizarse.
     * @throws \LogicException Si faltan adaptadores válidos.
     * @throws \InvalidArgumentException Si los datos no respetan el alcance o sus invariantes.
     */
    public function forProduct(string $productId, string $locationId): ?AvailabilityData
    {
        $product = $this->adapters->resolve('products')->find($productId);
        if ($product === null) {
            return null;
        }

        return $this->calculator->calculate(
            $product->id,
            $locationId,
            $this->adapters->resolve('inventory')->current($product->id, $locationId),
            $this->adapters->resolve('reservations')->activeFor($product->id, $locationId),
        );
    }
}
