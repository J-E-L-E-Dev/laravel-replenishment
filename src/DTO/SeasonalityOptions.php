<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Parámetros explícitos de exploración estadística, no políticas comerciales de compra. */
final class SeasonalityOptions
{
    /**
     * Valida límites y umbrales publicados en cada auditoría; pondera por igual los años seleccionados.
     * @param int $historyYears Máximo de años civiles recientes considerados.
     * @param int $minimumYears Mínimo de años completos y activos, nunca inferior a dos.
     * @param string $highIndex Umbral de intensidad alta.
     * @param string $lowIndex Umbral de intensidad baja.
     * @param string $recurrence Fracción mínima de años con intensidad alta.
     * @param string $maximumSpread Amplitud máxima de índices para estabilidad.
     * @param string $outlierFactor Factor respecto a mediana anual para señalar cambios extremos.
     * @param string $alpha Nivel del contraste exploratorio de signos con corrección de doce meses.
     * @param int $minimumActiveDays Mínimo exploratorio de días con ventas positivas por año; evita certificar recurrencia con actividad ínfima.
     * @throws InvalidArgumentException Si la configuración no define límites coherentes.
     */
    public function __construct(public readonly int $historyYears = 5, public readonly int $minimumYears = 3,
        public readonly string $highIndex = '1.20', public readonly string $lowIndex = '0.80',
        public readonly string $recurrence = '0.666666', public readonly string $maximumSpread = '0.50',
        public readonly string $outlierFactor = '3', public readonly string $alpha = '0.05', public readonly int $minimumActiveDays = 12)
    {
        if ($historyYears < 2 || $historyYears > 10 || $minimumYears < 2 || $minimumYears > $historyYears || $minimumActiveDays < 1 || $minimumActiveDays > 365
            || BigDecimal::of($highIndex)->isLessThanOrEqualTo(1) || BigDecimal::of($lowIndex)->isLessThan(0)
            || BigDecimal::of($lowIndex)->isGreaterThanOrEqualTo(1) || BigDecimal::of($recurrence)->isLessThanOrEqualTo('0.5')
            || BigDecimal::of($recurrence)->isGreaterThan(1) || BigDecimal::of($maximumSpread)->isLessThan(0)
            || BigDecimal::of($outlierFactor)->isLessThanOrEqualTo(1) || BigDecimal::of($alpha)->isLessThanOrEqualTo(0)
            || BigDecimal::of($alpha)->isGreaterThanOrEqualTo(1)) {
            throw new InvalidArgumentException('Los parámetros de estacionalidad requieren 2–10 años, recurrencia mayor a la mitad y umbrales coherentes.');
        }
    }
    /**
     * Devuelve parámetros reproducibles y política de ponderación explícita.
     * @return array Configuración auditable.
     */
    public function audit(): array { return get_object_vars($this) + ['weighting' => 'equal_selected_years', 'consolidation' => 'median']; }
}
