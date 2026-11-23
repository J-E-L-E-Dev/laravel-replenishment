<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** Ejecuta análisis observados con calendario del consumidor y corte predeterminado en el inicio del día actual. */
final class ObservedDemandQuery
{
    /**
     * Recibe adaptadores explícitos y servicios independientes.
     * @param AdapterRegistry $adapters Fuentes del consumidor.
     * @param ObservedDemandCalculator $calculator Agregador.
     * @param Repository $config Zona operativa.
     */
    public function __construct(private AdapterRegistry $adapters, private ObservedDemandCalculator $calculator, private Repository $config) {}

    /**
     * Analiza un producto excluyendo siempre días todavía en curso o futuros.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable|null $from Inicio; por defecto 365 días anteriores al corte.
     * @param DateTimeImmutable|null $until Fin; por defecto inicio del día operativo actual.
     * @return ObservedDemandData Resultado sin escrituras.
     * @throws InvalidArgumentException Si el corte incluye días aún no completados.
     */
    public function analyze(string $productId, string $locationId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): ObservedDemandData
    {
        $timezone = $this->config->get('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $until ??= $today;
        if ($until > $today) {
            throw new InvalidArgumentException('El corte debe excluir el día operativo en curso y las fechas futuras.');
        }
        $from ??= CarbonImmutable::instance($until)->setTimezone($timezone)->subDays(365);
        if ($this->config->get('replenishment.adapters.commercial_history') !== null) {
            $history = app(CommercialSalesQuery::class)->read($productId, $locationId, $from, $until, $timezone);
            return app(CommercialSalesReport::class)->project($history);
        }
        return $this->calculator->calculate($this->adapters->resolve('sales'), $productId, $locationId, $from, $until, $timezone);
    }
}

