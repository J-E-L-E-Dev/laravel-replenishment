<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Diagnóstico acotado de existencia física; la disponibilidad comercial histórica permanece desconocida. */
final class StockHistoryData
{
    /**
     * Transporta resumen, días y muestras limitadas sin guardar el libro completo de movimientos.
     * @param string $productId Producto.
     * @param string $locationId Ubicación.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Corte exclusivo.
     * @param string $timezone Zona operativa.
     * @param array $summary Conteos, cobertura y límites de interpretación.
     * @param array<string,array> $days Evidencia diaria, sin extrapolaciones.
     * @param array<StockObservationData> $observationSamples Hasta cincuenta observaciones originales con calidad.
     * @param array<StockIntervalData> $intervalSamples Hasta cincuenta tramos de ejemplo.
     */
    public function __construct(public readonly string $productId, public readonly string $locationId,
        public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until, public readonly string $timezone,
        public readonly array $summary, public readonly array $days, public readonly array $observationSamples,
        public readonly array $intervalSamples) {}

    /**
     * Devuelve únicamente diagnóstico agregado, sin miles de movimientos ni datos comerciales de documentos.
     * @return array Resumen serializable.
     */
    public function audit(): array
    {
        return ['product_id' => $this->productId, 'location_id' => $this->locationId, 'from' => $this->from->format(DATE_ATOM),
            'until' => $this->until->format(DATE_ATOM), 'timezone' => $this->timezone,
            'algorithm_version' => \Asisnet\Replenishment\Services\StockHistoryAnalyzer::VERSION, 'summary' => $this->summary];
    }
}
