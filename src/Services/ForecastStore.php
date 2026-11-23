<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ForecastData;
use Asisnet\Replenishment\Models\DemandForecast;
use Asisnet\Replenishment\Models\ProductMetric;
use Asisnet\Replenishment\Forecasting\ForecastMath;
use DateTimeImmutable;
use InvalidArgumentException;

/** Añade pronósticos y auditoría relacionada en una única transacción de la conexión propia. */
final class ForecastStore
{
    /**
     * Recibe persistencia aislada.
     * @param PersistenceConnection $connection Destino configurado del paquete.
     */
    public function __construct(private PersistenceConnection $connection) {}
    /**
     * Conserva categoría exploratoria o utilizable, sin sobrescribir históricos ni inventar límites.
     * @param ForecastData $result Cálculo íntegro con cantidades conocidas y categorías explícitas.
     * @return array Identidades de pronósticos y métrica de auditoría.
     * @throws InvalidArgumentException Si alguna cantidad o categoría no permite publicar el lote.
     * @throws \Throwable Si falla una escritura; se revierte el lote completo.
     */
    public function persist(ForecastData $result): array
    {
        if ($result->report['forecasts'] === []) { throw new InvalidArgumentException('No hay pronósticos para publicar.'); }
        foreach ($result->report['forecasts'] as $forecast) {
            if ($forecast['forecast_quantity'] === null || $forecast['method'] === null || !in_array($forecast['quality'], ['exploratory', 'usable'], true)) {
                throw new InvalidArgumentException('La evidencia es insuficiente; no se publican pronósticos desconocidos.');
            }
        }
        return $this->connection->transaction(function () use ($result): array {
            $ids = []; $source = $result->source; $generated = new DateTimeImmutable($result->report['generated_at']);
            foreach ($result->report['forecasts'] as $row) {
                $forecast = DemandForecast::create(['product_id' => $source->productId, 'location_id' => $source->locationId, 'generated_at' => $generated,
                    'period_start' => new DateTimeImmutable($row['period_start'].'T00:00:00', new \DateTimeZone($source->timezone)),
                    'period_end' => new DateTimeImmutable($row['period_end'].'T00:00:00', new \DateTimeZone($source->timezone)), 'granularity' => 'monthly',
                    'forecast_quantity' => ForecastMath::round($row['forecast_quantity'], 6), 'lower_bound' => null, 'upper_bound' => null, 'confidence' => null,
                    'base_demand' => $row['base_quantity'] === null ? null : ForecastMath::round($row['base_quantity'], 6),
                    'seasonal_index' => $row['seasonal_factor'] === null ? null : ForecastMath::round($row['seasonal_factor'], 6),
                    'trend_factor' => $row['trend_factor'] === null ? null : ForecastMath::round($row['trend_factor'], 6),
                    'method' => $row['method'], 'algorithm_version' => DemandForecastEngine::VERSION]);
                $ids[] = $forecast->getKey();
            }
            $metric = ProductMetric::create(['product_id' => $source->productId, 'location_id' => $source->locationId, 'calculated_at' => $generated,
                'analysis_start' => $source->from, 'analysis_end' => $source->until, 'algorithm_version' => DemandForecastEngine::VERSION,
                'observed_sales_summary' => ['forecast_audit' => $result->audit(true), 'demand_forecast_ids' => $ids]]);
            return ['forecast_ids' => $ids, 'audit_metric_id' => $metric->getKey()];
        });
    }
}
