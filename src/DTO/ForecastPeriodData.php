<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Mes civil de entrenamiento; NULL expresa una observación desconocida, no venta cero. */
final class ForecastPeriodData
{
    /**
     * Recibe evidencia mensual normalizada.
     * @param DateTimeImmutable $from Inicio inclusivo.
     * @param DateTimeImmutable $until Corte exclusivo.
     * @param int $days Días reales.
     * @param string|null $quantity FAC verificada o desconocida.
     * @param string|null $unit Unidad comparable.
     * @param array $issues Causas de exclusión.
     * @param array $complementary Devoluciones, reversiones y neto sin alterarlos.
     */
    public function __construct(public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until, public readonly int $days,
        public readonly ?string $quantity, public readonly ?string $unit, public readonly array $issues = [], public readonly array $complementary = []) {}
    /**
     * Devuelve evidencia resumida de este mes.
     * @return array Datos serializables.
     */
    public function audit(): array { return ['from' => $this->from->format(DATE_ATOM), 'until' => $this->until->format(DATE_ATOM), 'days' => $this->days, 'quantity' => $this->quantity, 'unit' => $this->unit, 'issues' => $this->issues, 'complementary' => $this->complementary]; }
}
