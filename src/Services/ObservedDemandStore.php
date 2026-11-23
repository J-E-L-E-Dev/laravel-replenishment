<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\Models\DailySale;
use Asisnet\Replenishment\Models\ProductMetric;
use DateTimeImmutable;

/** Persiste caché y métricas de forma atómica exclusivamente en la conexión propia. */
final class ObservedDemandStore
{
    /**
     * Recibe persistencia aislada y consolidador reutilizable.
     * @param PersistenceConnection $connection Destino propio.
     * @param ObservedDemandCalculator $calculator Agregador puro respecto a la base de datos.
     */
    public function __construct(private PersistenceConnection $connection, private ObservedDemandCalculator $calculator) {}

    /**
     * Reconstruye un intervalo completo y añade una nueva métrica inmutable en la misma transacción.
     * @param ObservedDemandData $result Cálculo completo del origen; se rechaza todo lote incompleto para proteger la caché verificada.
     * @return ProductMetric Nueva instantánea histórica.
     * @throws \Throwable Si falla la validación o una escritura; no se publica ninguna parte del lote.
     */
    public function persist(ObservedDemandData $result): ProductMetric
    {
        if ($result->origin !== 'source' || $result->version !== ObservedDemandCalculator::VERSION) {
            throw new \InvalidArgumentException('La caché sólo puede reconstruirse con una lectura finalizada del origen.');
        }
        return $this->connection->transaction(function () use ($result): ProductMetric {
            if (($result->summary['observation_status'] ?? null) !== 'complete') {
                throw new \InvalidArgumentException('No se publica una reconstrucción incompleta: la caché verificada permanece intacta.');
            }
            for ($date = $result->from; $date < $result->until; $date = $date->modify('+1 day')) {
                if (($result->days[$date->format('Y-m-d')]['observation_status'] ?? null) !== 'complete') {
                    throw new \InvalidArgumentException('La reconstrucción contiene días ausentes o incompletos; no se publicó ningún cambio.');
                }
            }
            $now = $result->calculatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $batch = [];
            foreach ($result->days as $date => $day) {
                unset($day['cached_calculated_at']);
                $model = new DailySale($day + ['product_id' => $result->productId, 'location_id' => $result->locationId,
                    'sales_date' => $date, 'observation_timezone' => $result->timezone, 'algorithm_version' => ObservedDemandCalculator::VERSION,
                    'calculated_at' => $result->calculatedAt, 'created_at' => $now, 'updated_at' => $now]);
                $batch[] = $model->validatedCacheAttributes();
                if (count($batch) === 50) {
                    $this->writeBatch($batch); $batch = [];
                }
            }
            if ($batch !== []) { $this->writeBatch($batch); }
            return ProductMetric::create(['product_id' => $result->productId, 'location_id' => $result->locationId,
                'calculated_at' => $result->calculatedAt, 'analysis_start' => $result->from, 'analysis_end' => $result->until,
                'algorithm_version' => ObservedDemandCalculator::VERSION, 'observed_days' => $result->summary['observed_days'],
                'gross_sales' => $result->summary['gross_sales_units'], 'returns' => $result->summary['returned_units'],
                'reversed_returns' => $result->summary['reversed_return_units'], 'net_sales' => $result->summary['net_sales_units'],
                'observed_sales_summary' => $result->audit()]);
        });
    }

    /**
     * Consulta series ya calculadas sin acceder al ERP; huecos, versiones y zonas incompatibles son desconocidos.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Fin exclusivo.
     * @param string $timezone Zona operativa.
     * @param \Asisnet\Replenishment\DTO\ProductData|null $master Ficha verificada para la proyección versionada.
     * @param bool $snapshot Permite consulta histórica sin acreditar vigencia actual.
     * @param int|null $runId Limita al mismo histórico que los demás componentes del diagnóstico.
     * @return ObservedDemandData Resúmenes de caché con fechas originales de cálculo por día.
     */
    public function read(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until, string $timezone, ?\Asisnet\Replenishment\DTO\ProductData $master = null, bool $snapshot = false, ?int $runId = null): ObservedDemandData
    {
        [$from, $until] = $this->calculator->bounds($from, $until, $timezone);
        if (app(CommercialProfile::class)->enabled()) {
            $data = app(CommercialSalesStore::class)->cached($productId, $locationId, $from, $until, $timezone, $master, $snapshot, $runId);
            return app(CommercialSalesReport::class)->project($data);
        }
        $days = [];
        foreach (DailySale::query()->where('product_id', $productId)->where('location_id', $locationId)
            ->where('sales_date', '>=', $from->format('Y-m-d'))->where('sales_date', '<', $until->format('Y-m-d'))->orderBy('id')->lazyById(250) as $row) {
            $day = [];
            foreach ([...ObservedDemandCalculator::QUANTITIES, 'base_unit', 'event_count', 'observation_status', 'quality_issues', 'inventory_quality_issues'] as $field) {
                $day[$field] = $row->getAttribute($field);
            }
            $day['cached_calculated_at'] = $row->calculated_at->format(DATE_ATOM);
            if ($row->algorithm_version !== ObservedDemandCalculator::VERSION || $row->observation_timezone !== $timezone) {
                foreach ([...ObservedDemandCalculator::QUANTITIES, 'event_count'] as $field) {
                    $day[$field] = null;
                }
                $day['observation_status'] = 'incomplete';
                $day['quality_issues'][] = 'cache_version_or_timezone_mismatch';
            }
            $days[$row->sales_date] = $day;
        }
        return $this->calculator->fromDays($productId, $locationId, $from, $until, $timezone, $days, 'cache');
    }

    /**
     * Escribe únicamente caché analítica validada, en bloques inferiores al límite de parámetros de SQL Server.
     * @param array<array> $batch Hasta cincuenta observaciones validadas mediante DailySale.
     * @return void
     */
    private function writeBatch(array $batch): void
    {
        $update = array_values(array_diff(array_keys($batch[0]), ['product_id', 'location_id', 'sales_date', 'created_at']));
        $this->connection->connection()->table('replenishment_daily_sales')->upsert($batch, ['product_id', 'location_id', 'sales_date'], $update);
    }
}

