<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Contracts;
use Asisnet\Replenishment\DTO\ForecastOptions;
use Asisnet\Replenishment\DTO\ForecastPeriodData;
use DateTimeImmutable;

/** Método predictivo puro: sólo recibe observaciones anteriores a su origen. */
interface ForecastModelInterface
{
    /**
     * Identifica método y parámetros fijos.
     * @return string Identidad reproducible.
     */
    public function name(): string;
    /**
     * Ajusta y pronostica un horizonte mensual sin consultar otros datos.
     * @param array<ForecastPeriodData> $training Meses consecutivos y verificados, anteriores al origen.
     * @param DateTimeImmutable $origin Inicio del primer mes futuro.
     * @param int $horizon Distancia del mes objetivo, desde uno.
     * @param ForecastOptions $options Parámetros explícitos.
     * @param array|null $seasonality Evidencia recalculada exclusivamente dentro de este entrenamiento.
     * @return array|null Cantidad, base y efectos aplicados; NULL si el método no es elegible.
     */
    public function predict(array $training, DateTimeImmutable $origin, int $horizon, ForecastOptions $options, ?array $seasonality = null): ?array;
}
