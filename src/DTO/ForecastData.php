<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;

/** Pronóstico histórico auditable, sin representar demanda potencial ni órdenes de compra. */
final class ForecastData
{
    /**
     * Recibe la serie comercial original y el diagnóstico inmutable.
     * @param ObservedDemandData $source Serie de entrenamiento.
     * @param array $report Pronósticos, evaluación, opciones y límites.
     */
    public function __construct(public readonly ObservedDemandData $source, public readonly array $report) {}
    /**
     * Resume la salida CLI; la auditoría persistente conserva todos los pares retrospectivos.
     * @param bool $detailed Incluye meses y errores individuales para trazabilidad propia.
     * @return array Auditoría serializable, sin movimientos ERP.
     */
    public function audit(bool $detailed = false): array
    {
        if ($detailed) { return $this->report; } $result = $this->report;
        $result['training']['excluded_months'] = array_values(array_map(fn ($p): array => ['from' => $p['from'], 'issues' => $p['issues']],
            array_filter($result['training']['months'], fn ($p): bool => $p['quantity'] === null)));
        unset($result['training']['months']);
        foreach ($result['backtesting'] as &$horizon) {
            foreach ($horizon['candidates'] as &$candidate) {
                foreach (['selection', 'validation'] as $part) {
                    $candidate[$part] = array_intersect_key($candidate[$part]['metrics'], array_flip(['observations', 'excluded_periods', 'mae', 'wape', 'bias_ratio', 'mase']));
                }
            }
            unset($candidate);
        }
        unset($horizon);
        return $result;
    }
}
