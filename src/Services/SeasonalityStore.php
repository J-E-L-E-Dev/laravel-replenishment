<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\SeasonalityData;
use Asisnet\Replenishment\Models\SeasonalPattern;
use Asisnet\Replenishment\Models\ProductMetric;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Publica patrones nuevos y su auditoría vinculada atómicamente en la conexión propia. */
final class SeasonalityStore
{
    /**
     * Recibe el destino exclusivo de persistencia.
     * @param PersistenceConnection $connection Conexión configurada.
     */
    public function __construct(private PersistenceConnection $connection) {}
    /**
     * Añade doce patrones y una auditoría histórica sin sobrescribir cálculos previos ni reconstruir caché.
     * @param SeasonalityData $result Diagnóstico con índices sustentados por suficientes años.
     * @return array Identidades de patrones y métrica que conserva años, exclusiones, opciones y magnitudes.
     * @throws InvalidArgumentException Si falta evidencia para publicar índices.
     * @throws \Throwable Si falla cualquier escritura; se revierte el conjunto.
     */
    public function persist(SeasonalityData $result): array
    {
        if ($result->report['quality'] === 'insufficient' || count($result->report['months']) !== 12) {
            throw new InvalidArgumentException('La evidencia es insuficiente; no se guardan índices estacionales.');
        }
        return $this->connection->transaction(function () use ($result): array {
            $ids = []; $source = $result->source;
            foreach ($result->report['months'] as $month => $value) {
                if ($value['index'] === null || $value['mean_observed_units'] === null) { throw new InvalidArgumentException('No se publican índices desconocidos.'); }
                $pattern = SeasonalPattern::create(['product_id' => $source->productId, 'location_id' => $source->locationId,
                    'period_type' => 'monthly', 'period_number' => $month, 'historical_years' => count($result->report['accepted_years']),
                    'seasonal_index' => (string) BigDecimal::of($value['index'])->toScale(6, RoundingMode::HALF_UP),
                    'mean_demand' => (string) BigDecimal::of($value['mean_observed_units'])->toScale(6, RoundingMode::HALF_UP),
                    'period_class' => $value['quality'], 'confidence' => null, 'calculated_at' => $source->calculatedAt, 'algorithm_version' => SeasonalityAnalyzer::VERSION]);
                $ids[] = $pattern->getKey();
            }
            $metric = ProductMetric::create(['product_id' => $source->productId, 'location_id' => $source->locationId,
                'analysis_start' => $source->from, 'analysis_end' => $source->until, 'calculated_at' => $source->calculatedAt,
                'algorithm_version' => SeasonalityAnalyzer::VERSION, 'observed_sales_summary' => ['seasonality' => $result->audit(), 'seasonal_pattern_ids' => $ids]]);
            return ['pattern_ids' => $ids, 'audit_metric_id' => $metric->getKey()];
        });
    }
}
