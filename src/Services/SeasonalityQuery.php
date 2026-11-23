<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\SeasonalityData;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use RuntimeException;

/** Coordina consulta directa o caché exclusiva; no abre el destino propio en modo directo. */
final class SeasonalityQuery
{
    /**
     * Recibe fuentes y configuración existentes.
     * @param ObservedDemandQuery $source Consulta comercial.
     * @param ObservedDemandStore $cache Caché comercial protegida.
     * @param SeasonalityAnalyzer $analyzer Cálculo puro.
     * @param PersistenceConnection $connection Destino propio opcional.
     * @param Repository $config Parámetros y zona.
     */
    public function __construct(private ObservedDemandQuery $source, private ObservedDemandStore $cache, private SeasonalityAnalyzer $analyzer,
        private PersistenceConnection $connection, private Repository $config) {}
    /**
     * Ejecuta un diagnóstico, sin publicar datos ni sustituir una caché ausente por consultas ERP.
     * @param string $productId Producto explícito.
     * @param string $locationId Ubicación explícita.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Corte exclusivo.
     * @param bool $cached Usa exclusivamente caché existente.
     * @param \Asisnet\Replenishment\DTO\ProductData|null $master Ficha para exigir el perfil vigente.
     * @param bool $snapshot Autoriza analizar una única fotografía histórica.
     * @return SeasonalityData Evidencia resumida.
     * @throws RuntimeException Si se solicita caché sin tabla propia.
     * @throws InvalidArgumentException Si el corte o la configuración son inválidos.
     */
    public function analyze(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until, bool $cached = false, ?\Asisnet\Replenishment\DTO\ProductData $master = null, bool $snapshot = false): SeasonalityData
    {
        $timezone = $this->config->get('app.timezone', 'UTC');
        if ($productId === '' || $locationId === '' || $until > CarbonImmutable::now($timezone)->startOfDay()) { throw new InvalidArgumentException('Use producto, ubicación y un corte anterior al día operativo en curso.'); }
        $options = $this->options();
        if ($cached && !$this->connection->connection()->getSchemaBuilder()->hasTable('replenishment_daily_sales')) {
            throw new RuntimeException('No existe la caché propia. Revise replenishment:migrate; no se ejecutaron migraciones. Puede consultar sin --cache.');
        }
        $data = $cached ? $this->cache->read($productId, $locationId, $from, $until, $timezone, $master, $snapshot) : $this->source->analyze($productId, $locationId, $from, $until);
        return $this->analyzer->analyze($data, $options);
    }
    /**
     * Resuelve el mínimo de la política configurada sin consultar tablas ni inventar una política activa.
     * @return SeasonalityOptions Parámetros explícitos.
     */
    private function options(): SeasonalityOptions
    {
        $c = $this->config->get('replenishment.seasonality', []);
        return new SeasonalityOptions((int) ($c['history_years'] ?? 5), (int) ($this->config->get('replenishment.policy_defaults.seasonal_minimum_years') ?? $c['minimum_years'] ?? 3),
            (string) ($c['high_index'] ?? '1.20'), (string) ($c['low_index'] ?? '0.80'), (string) ($c['recurrence'] ?? '0.666666'),
            (string) ($c['maximum_spread'] ?? '0.50'), (string) ($c['outlier_factor'] ?? '3'), (string) ($c['alpha'] ?? '0.05'), (int) ($c['minimum_active_days'] ?? 12));
    }
}
