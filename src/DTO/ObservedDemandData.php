<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Resultado auditable de ventas observadas, sin inferir demanda perdida ni disponibilidad. */
final class ObservedDemandData
{
    /**
     * Recibe una serie diaria acotada y sus resúmenes derivados, nunca movimientos individuales.
     * @param string $productId Producto opaco.
     * @param string $locationId Ubicación explícita.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Corte exclusivo.
     * @param string $timezone Zona operativa de las fechas civiles.
     * @param DateTimeImmutable $calculatedAt Momento de cálculo.
     * @param array<string,array> $days Serie completa de días, incluidos desconocidos.
     * @param array $summary Indicadores del intervalo y causas de incertidumbre.
     * @param array<int,array> $windows Ventanas acumuladas y comparación previa no superpuesta.
     * @param array<string,array> $months Totales mensuales con límites observados explícitos.
     * @param array<string,array> $years Totales anuales con límites observados explícitos.
     * @param string $origin source o cache; la caché puede estar desactualizada respecto al origen.
     * @param string $version Versión que produjo la serie.
     * @param array $statistics Perfil, ejecución y cobertura de una proyección versionada.
     */
    public function __construct(
        public readonly string $productId, public readonly string $locationId,
        public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until,
        public readonly string $timezone, public readonly DateTimeImmutable $calculatedAt,
        public readonly array $days, public readonly array $summary, public readonly array $windows,
        public readonly array $months, public readonly array $years, public readonly string $origin = 'source',
        public readonly string $version = 'observed-sales-1.0', public readonly array $statistics = [],
    ) {}

    /**
     * Devuelve la auditoría resumida sin duplicar la serie diaria en cada histórico de métricas.
     * @return array Resumen serializable con límites, zona, origen y versión.
     */
    public function audit(): array
    {
        return ['product_id' => $this->productId, 'location_id' => $this->locationId,
            'from' => $this->from->format(DATE_ATOM), 'until' => $this->until->format(DATE_ATOM),
            'timezone' => $this->timezone, 'calculated_at' => $this->calculatedAt->format(DATE_ATOM),
            'algorithm_version' => $this->version,
            'origin' => $this->origin, 'summary' => $this->summary, 'windows' => $this->windows,
            'months' => $this->months, 'years' => $this->years] + ($this->statistics === [] ? [] : ['statistics' => $this->statistics]);
    }
}

