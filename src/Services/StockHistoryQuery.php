<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\StockHistoryData;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** Coordina fuentes ya configuradas sin consultar reservas actuales ni persistir el diagnóstico. */
final class StockHistoryQuery
{
    /**
     * Recibe registro y servicios aprobados.
     * @param AdapterRegistry $adapters Fuentes existentes.
     * @param StockHistoryAnalyzer $analyzer Evaluador físico.
     * @param ObservedDemandQuery $sales Ventas observadas.
     * @param ObservedDemandStore $cache Caché comercial opcional.
     * @param Repository $config Zona operativa.
     */
    public function __construct(private AdapterRegistry $adapters, private StockHistoryAnalyzer $analyzer,
        private ObservedDemandQuery $sales, private ObservedDemandStore $cache, private Repository $config) {}

    /**
     * Diagnostica un intervalo completo sin presumir cobertura documental del adaptador.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param string $salesMode none, source o cache para el contraste comercial opcional.
     * @return StockHistoryData Diagnóstico de sólo lectura; disponibilidad histórica siempre desconocida.
     * @throws InvalidArgumentException Si el modo o corte no son válidos.
     */
    public function analyze(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until, string $salesMode = 'none'): StockHistoryData
    {
        $timezone = $this->config->get('app.timezone', 'UTC');
        if (!in_array($salesMode, ['none', 'source', 'cache'], true) || $until > CarbonImmutable::now($timezone)->startOfDay()) {
            throw new InvalidArgumentException('Use un modo de ventas válido y un corte que excluya el día operativo en curso.');
        }
        $sales = $salesMode === 'source' ? $this->sales->analyze($productId, $locationId, $from, $until)
            : ($salesMode === 'cache' ? $this->cache->read($productId, $locationId, $from, $until, $timezone) : null);
        return $this->analyzer->analyze($this->adapters->resolve('movements'), $productId, $locationId, $from, $until, $timezone, $sales);
    }
}
