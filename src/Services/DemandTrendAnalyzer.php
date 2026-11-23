<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Diagnóstico descriptivo de ventanas no superpuestas; no certifica pronósticos ni causas del descenso. */
final class DemandTrendAnalyzer
{
    /** Recibe el agregador común. @param ObservedDemandCalculator $calculator Serie y calidad comercial. */
    public function __construct(private ObservedDemandCalculator $calculator) {}

    /**
     * Compara ventanas recientes con sus anteriores y exige concordancia de dos escalas para declarar crecimiento o descenso.
     * @param ObservedDemandData $data Historia diaria normalizada anterior al corte.
     * @param array<int> $windows Escalas explícitas, por defecto 30, 90 y 180 días.
     * @param string $threshold Cambio relativo mínimo descriptivo, sin significación estadística.
     * @param string $signal Señal comercial elegida para cantidades, tasas y frecuencia.
     * @return array Clase, ventanas, frecuencia y variabilidad descriptiva exacta.
     * @throws InvalidArgumentException Si ventanas o umbral no son válidos.
     */
    public function analyze(ObservedDemandData $data, array $windows = [30, 90, 180], string $threshold = '0.20', string $signal = 'verified_gross_sales'): array
    {
        $field = match ($signal) {
            'verified_gross_sales' => 'gross_sales_units',
            'verified_net_sales' => 'net_sales_units',
            default => throw new InvalidArgumentException('Señal comercial desconocida.'),
        };
        $limit = BigDecimal::of($threshold);
        if ($limit->isNegative() || $limit->isGreaterThan(1) || count($windows) < 2 || count($windows) > 6 || count(array_unique($windows)) !== count($windows)) { throw new InvalidArgumentException('Defina entre dos y seis ventanas distintas y un umbral entre cero y uno.'); }
        $rows = []; $down = 0; $up = 0; $valid = 0;
        foreach ($windows as $size) {
            if (!is_int($size) || $size < 7 || $size > 365) { throw new InvalidArgumentException('Las ventanas deben contener entre siete y 365 días.'); }
            $recentStart = $data->until->modify('-'.$size.' days'); $priorStart = $recentStart->modify('-'.$size.' days');
            $recent = $this->calculator->fromDays($data->productId, $data->locationId, $recentStart, $data->until, $data->timezone, $data->days, $data->origin, $data->version, $data->statistics, $data->calculatedAt);
            $prior = $this->calculator->fromDays($data->productId, $data->locationId, $priorStart, $recentStart, $data->timezone, $data->days, $data->origin, $data->version, $data->statistics, $data->calculatedAt);
            $comparable = $priorStart >= $data->from && $recent->summary['observation_status'] === 'complete' && $prior->summary['observation_status'] === 'complete';
            $units = []; foreach ($data->days as $date => $day) { if ($date >= $priorStart->format('Y-m-d') && ($day['base_unit'] ?? null) !== null) { $units[$day['base_unit']] = true; } }
            $comparable = $comparable && count($units) === 1;
            $change = null;
            if ($comparable && BigDecimal::of($prior->summary[$field])->isPositive()) {
                $change = BigDecimal::of($recent->summary[$field])->minus($prior->summary[$field])->dividedBy($prior->summary[$field], 12, RoundingMode::HALF_UP);
                $valid++; $down += (int) $change->isLessThan($limit->negated()); $up += (int) $change->isGreaterThan($limit);
            }
            $rows[] = ['days' => $size, 'recent' => $recent->summary, 'previous' => $prior->summary, 'comparable' => $comparable,
                'recent_quantity'=>$recent->summary[$field], 'previous_quantity'=>$prior->summary[$field],
                'recent_rate'=>$comparable ? (string)BigDecimal::of($recent->summary[$field])->dividedBy($size,12,RoundingMode::HALF_UP) : null,
                'previous_rate'=>$comparable ? (string)BigDecimal::of($prior->summary[$field])->dividedBy($size,12,RoundingMode::HALF_UP) : null,
                'relative_change' => $change === null ? null : (string) $change, 'excluded_reason' => !$comparable ? 'Cobertura o unidad insuficiente.' : ($change === null ? 'El período anterior no tiene ventas positivas.' : null)];
        }
        $blocks = []; $decliningBlocks = 0; $growingBlocks = 0;
        // Tres bloques recientes disjuntos se comparan con los mismos bloques de los noventa días anteriores.
        for ($index = 0; $index < 3; $index++) {
            $finish = $data->until->modify('-'.($index * 30).' days'); $begin = $finish->modify('-30 days');
            $recent = $this->calculator->fromDays($data->productId, $data->locationId, $begin, $finish, $data->timezone, $data->days, $data->origin, $data->version, $data->statistics, $data->calculatedAt)->summary;
            $previous = $this->calculator->fromDays($data->productId, $data->locationId, $begin->modify('-90 days'), $finish->modify('-90 days'), $data->timezone, $data->days, $data->origin, $data->version, $data->statistics, $data->calculatedAt)->summary;
            $change = null;
            if ($recent['observation_status'] === 'complete' && $previous['observation_status'] === 'complete' && BigDecimal::of($previous[$field])->isPositive()) {
                $change = BigDecimal::of($recent[$field])->minus($previous[$field])->dividedBy($previous[$field], 12, RoundingMode::HALF_UP);
                $decliningBlocks += (int) $change->isLessThan($limit->negated()); $growingBlocks += (int) $change->isGreaterThan($limit);
            }
            $blocks[] = ['recent' => $recent, 'previous' => $previous, 'relative_change' => $change === null ? null : (string) $change];
        }
        $frequency = $data->summary['sales_frequency'];
        if ($frequency !== null && $signal === 'verified_net_sales') {
            $active = count(array_filter($data->days, fn ($day) => BigDecimal::of($day[$field])->isPositive()));
            $frequency = (string)BigDecimal::of($active)->dividedBy(count($data->days), 12, RoundingMode::HALF_UP);
        }
        $class = $valid < 2 ? 'insufficient' : ($down >= 2 && $decliningBlocks >= 2 && $up === 0 ? 'declining' : ($up >= 2 && $growingBlocks >= 2 && $down === 0 ? 'growing' : 'stable'));
        if (count(array_filter(array_column($rows, 'comparable'))) >= 2 && $frequency !== null
            && BigDecimal::of($frequency)->isPositive() && BigDecimal::of($frequency)->isLessThan('0.20')) { $class = 'intermittent'; }
        $minimum = null; $maximum = null;
        if ($data->summary['observation_status'] === 'complete') { foreach ($data->days as $day) { $value = BigDecimal::of($day[$field]); $minimum = $minimum === null ? $value : BigDecimal::min($minimum, $value); $maximum = $maximum === null ? $value : BigDecimal::max($maximum, $value); } }
        return ['signal'=>$signal, 'quality'=>$class === 'insufficient' ? 'insufficient' : 'descriptive', 'classification' => $class, 'windows' => $rows, 'recent_30_day_blocks' => $blocks, 'threshold' => $threshold, 'sales_frequency' => $frequency,
            'daily_range' => $minimum === null ? null : ['minimum' => (string) $minimum, 'maximum' => (string) $maximum],
            'confidence' => null, 'warnings' => ['Clasificación descriptiva; ventanas de distintas escalas pueden compartir observaciones.', 'Un descenso observado no demuestra agotamiento, demanda perdida ni una caída futura perpetua.']];
    }
}
