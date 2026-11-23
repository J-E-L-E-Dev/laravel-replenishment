<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ForecastData;
use Asisnet\Replenishment\DTO\ForecastOptions;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use RuntimeException;

/** Coordina entrenamiento comercial directo o exclusivamente desde caché sin persistir automáticamente. */
final class ForecastQuery
{
    /**
     * Recibe servicios ya existentes y motor independiente.
     * @param ObservedDemandQuery $source Consulta del origen.
     * @param ObservedDemandStore $cache Caché protegida.
     * @param DemandForecastEngine $engine Motor.
     * @param PersistenceConnection $connection Destino propio opcional.
     * @param Repository $config Configuración estadística y zona.
     */
    public function __construct(private ObservedDemandQuery $source, private ObservedDemandStore $cache, private DemandForecastEngine $engine,
        private PersistenceConnection $connection, private Repository $config) {}
    /**
     * El fin del entrenamiento es el origen explícito; no incluye meses en curso ni datos posteriores.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio mensual inclusivo.
     * @param DateTimeImmutable $until Corte mensual exclusivo.
     * @param int|null $horizon Meses futuros o configuración predeterminada.
     * @param bool $cached Prohíbe consultar ERP cuando es verdadero.
     * @param bool $backtest Activa validación temporal.
     * @param int|null $seasonalMinimumYears Mínimo de años de la política seleccionada, sin cambiar configuración global.
     * @param \Asisnet\Replenishment\DTO\ProductData|null $master Ficha verificada para validar el perfil de caché.
     * @param int|null $runId Ejecución comercial común de la planificación.
     * @param bool $snapshot Autoriza analizar una única fotografía histórica desde caché.
     * @return ForecastData Pronósticos y evidencia sin escrituras.
     * @throws InvalidArgumentException Si el intervalo, alcance o opciones no son válidos.
     * @throws RuntimeException Si se solicita caché inexistente; nunca migra ni cambia de fuente.
     */
    public function analyze(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until, ?int $horizon = null, bool $cached = false, bool $backtest = true, ?int $seasonalMinimumYears = null, ?\Asisnet\Replenishment\DTO\ProductData $master = null, ?int $runId = null, bool $snapshot = false): ForecastData
    {
        $timezone = $this->config->get('app.timezone', 'UTC'); $from = $from->setTimezone(new \DateTimeZone($timezone)); $until = $until->setTimezone(new \DateTimeZone($timezone));
        if ($productId === '' || $locationId === '' || $from >= $until || $until > $from->modify('+10 years')
            || $from->format('d H:i:s.u') !== '01 00:00:00.000000' || $until->format('d H:i:s.u') !== '01 00:00:00.000000'
            || $until > CarbonImmutable::now($timezone)->startOfMonth()) {
            throw new InvalidArgumentException('Use producto, ubicación y fechas del primer día del mes; el corte excluye el mes en curso y admite hasta diez años.');
        }
        $c = $this->config->get('replenishment.forecast', []);
        $options = new ForecastOptions($horizon ?? (int) ($c['horizon'] ?? 3), (int) ($c['window'] ?? 12), (int) ($c['minimum_training'] ?? 3),
            (int) ($c['minimum_selection'] ?? 6), (int) ($c['validation_origins'] ?? 3), (int) ($c['maximum_origins'] ?? 24),
            $c['alphas'] ?? ['0.2', '0.5', '0.8'], (string) ($c['recent_weight'] ?? '1'), (string) ($c['damping'] ?? '0.8'),
            (string) ($c['maximum_trend_factor'] ?? '2'), (string) ($c['intermittent_fraction'] ?? '0.4'), (int) ($c['minimum_positive_months'] ?? 3),
            (string) ($c['improvement'] ?? '0.05'), (string) ($c['maximum_wape'] ?? '0.50'), (string) ($c['maximum_bias'] ?? '0.25'),
            (int) ($c['maximum_cache_age_days'] ?? 30), $backtest);
        $s = $this->config->get('replenishment.seasonality', []);
        $seasonal = new SeasonalityOptions($seasonalMinimumYears===null?(int)($s['history_years']??5):max((int)($s['history_years']??5),$seasonalMinimumYears), (int) ($seasonalMinimumYears ?? $this->config->get('replenishment.policy_defaults.seasonal_minimum_years') ?? $s['minimum_years'] ?? 3),
            (string) ($s['high_index'] ?? '1.20'), (string) ($s['low_index'] ?? '0.80'), (string) ($s['recurrence'] ?? '0.666666'),
            (string) ($s['maximum_spread'] ?? '0.50'), (string) ($s['outlier_factor'] ?? '3'), (string) ($s['alpha'] ?? '0.05'), (int) ($s['minimum_active_days'] ?? 12));
        if ($cached && !$this->connection->connection()->getSchemaBuilder()->hasTable('replenishment_daily_sales')) {
            throw new RuntimeException('No existe la caché propia. Revise replenishment:migrate; no se ejecutaron migraciones. Puede consultar sin --cache.');
        }
        $data = $cached ? $this->cache->read($productId, $locationId, $from, $until, $timezone, $master, $snapshot, $runId) : $this->source->analyze($productId, $locationId, $from, $until);
        return $this->engine->generate($data, $options, $seasonal);
    }
}
