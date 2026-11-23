<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\ForecastData;
use Asisnet\Replenishment\DTO\ForecastOptions;
use Asisnet\Replenishment\DTO\SeasonalityOptions;
use Asisnet\Replenishment\Forecasting\StatisticalForecastModel;
use Asisnet\Replenishment\Forecasting\ForecastMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;

/** Selecciona por pasado, comprueba con reserva temporal y emite ventas observables sin decisiones de compra. */
final class DemandForecastEngine
{
    /** @var string Versión de entrenamiento, particiones, fórmulas y calidad. */
    public const VERSION = 'observed-forecast-1.0';
    /**
     * Recibe servicios puros y reutilizables.
     * @param ForecastTraining $training Preparación.
     * @param ForecastBacktester $backtester Evaluación temporal.
     */
    public function __construct(private ForecastTraining $training, private ForecastBacktester $backtester) {}
    /**
     * Genera hasta seis meses posteriores al corte, sin observar datos posteriores a él.
     * @param ObservedDemandData $source Serie cuyo fin exclusivo fija el corte.
     * @param ForecastOptions $options Parámetros de ajuste y calidad.
     * @param SeasonalityOptions|null $seasonalOptions Política estacional aprobada.
     * @return ForecastData Resultado con cantidades desconocidas si no existe entrenamiento suficiente.
     * @throws \InvalidArgumentException Si el corte no delimita meses civiles completos.
     */
    public function generate(ObservedDemandData $source, ForecastOptions $options, ?SeasonalityOptions $seasonalOptions = null): ForecastData
    {
        $seasonalOptions ??= new SeasonalityOptions();
        $periods = $this->training->periods($source); $train = $this->training->suffix($periods); $models = $this->models($options);
        $units = array_values(array_unique(array_filter(array_map(fn ($p) => $p->unit, $train), fn ($unit): bool => $unit !== null)));
        $seasonality = $this->training->seasonal($source, $train, $seasonalOptions);
        $evaluation = $options->backtest ? $this->backtester->run($source, $periods, $models, $options, $seasonalOptions) : [];
        $cacheStale = false;
        if ($source->origin === 'cache') {
            $oldest = $source->summary['cache_calculated_at_min'] ?? null;
            $cacheStale = $oldest === null || new DateTimeImmutable($oldest) < (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-'.$options->maximumCacheAgeDays.' days');
        }
        $forecasts = []; $positive = count(array_filter($train, fn ($p): bool => BigDecimal::of($p->quantity)->isPositive()));
        for ($h = 1; $h <= $options->horizon; $h++) {
            $candidates = $evaluation[$h]['candidates'] ?? []; $selected = 'mean'; $selectionReason = 'exploratory_mean_without_comparison';
            $baseSelection = $candidates['mean']['selection'] ?? null;
            $baseMae = $baseSelection['metrics']['mae'] ?? null;
            $bestMae = $baseMae;
            $predictions = []; foreach ($models as $model) { $predictions[$model->name()] = $model->predict($train, $source->until, $h, $options, $seasonality); }
            if (($baseSelection['metrics']['observations'] ?? 0) >= $options->minimumSelection) {
                $selectionReason = 'simple_mean_retained';
                foreach ($models as $model) {
                    $name = $model->name(); if ($name === 'mean' || $predictions[$name] === null) { continue; }
                    $candidate = $candidates[$name]['selection'] ?? null;
                    if ($candidate === null || !$this->sameOrigins($baseSelection, $candidate) || $candidate['metrics']['mae'] === null) { continue; }
                    $mae = $candidate['metrics']['mae'];
                    if (BigDecimal::of($mae)->isLessThan($bestMae) && BigDecimal::of($mae)->isLessThanOrEqualTo(BigDecimal::of($baseMae)->multipliedBy(BigDecimal::one()->minus($options->improvement)))) {
                        $selected = $name; $bestMae = $mae; $selectionReason = 'lower_selection_mae_on_same_origins';
                    }
                }
            }
            $prediction = $predictions[$selected]; $quality = $prediction === null ? 'insufficient' : 'exploratory'; $reasons = [];
            $validation = $candidates[$selected]['validation'] ?? null; $baselineValidation = $candidates['mean']['validation'] ?? null;
            $metrics = $validation['metrics'] ?? null;
            if ($prediction !== null && ($baseSelection['metrics']['observations'] ?? 0) >= $options->minimumSelection
                && ($metrics['observations'] ?? 0) >= $options->validationOrigins && $metrics['wape'] !== null && $metrics['bias_ratio'] !== null
                && BigDecimal::of($metrics['wape'])->isLessThanOrEqualTo($options->maximumWape)
                && BigDecimal::of($metrics['bias_ratio'])->abs()->isLessThanOrEqualTo($options->maximumBias)) {
                $improves = $selected === 'mean' || ($this->sameOrigins($baselineValidation, $validation)
                    && BigDecimal::of($metrics['mae'])->isLessThan(BigDecimal::of($baselineValidation['metrics']['mae']))
                    && BigDecimal::of($metrics['mae'])->isLessThanOrEqualTo(BigDecimal::of($baselineValidation['metrics']['mae'])->multipliedBy(BigDecimal::one()->minus($options->improvement))));
                if ($improves && !$cacheStale && $positive >= $options->minimumPositiveMonths) { $quality = 'usable'; }
                elseif (!$improves) { $reasons[] = 'selected_method_not_confirmed_on_reserved_validation'; }
            }
            if ($quality === 'exploratory') { $reasons[] = 'validation_or_error_thresholds_not_satisfied'; }
            if ($cacheStale) { $reasons[] = 'cache_stale_or_freshness_unknown'; }
            if ($positive < $options->minimumPositiveMonths) { $reasons[] = 'few_positive_months'; }
            if ($prediction === null) { $reasons[] = 'insufficient_consecutive_training'; }
            $start = $source->until->modify('+'.($h - 1).' months');
            $forecasts[] = ['horizon' => $h, 'period_start' => $start->format('Y-m-d'), 'period_end' => $start->modify('+1 month')->format('Y-m-d'),
                'forecast_quantity' => $prediction['quantity'] ?? null, 'display_quantity' => $prediction === null ? null : ForecastMath::round($prediction['quantity'], 2),
                'base_quantity' => $prediction['base_quantity'] ?? null, 'seasonal_factor' => $prediction['seasonal_factor'] ?? null,
                'trend_factor' => $prediction['trend_factor'] ?? null, 'method' => $prediction === null ? null : $selected,
                'quality' => $quality, 'training_periods' => $prediction['training_periods'] ?? count($train),
                'model_details' => $prediction['details'] ?? [], 'selection_reason' => $selectionReason, 'quality_reasons' => $reasons,
                'validation' => $metrics, 'lower_bound' => null, 'upper_bound' => null, 'confidence' => null];
        }
        return new ForecastData($source, ['product_id' => $source->productId, 'location_id' => $source->locationId, 'cutoff' => $source->until->format(DATE_ATOM),
            'base_unit' => count($units) === 1 ? $units[0] : null,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'), 'timezone' => $source->timezone,
            'signal' => 'verified_gross_sales', 'algorithm_version' => self::VERSION, 'source_algorithm_version' => $source->version, 'source_statistics' => $source->statistics,
            'origin' => $source->origin, 'source_reading_status' => $source->summary['observation_status'], 'source_quality_issues' => $source->summary['sales_quality_issues'],
            'options' => $options->audit(), 'seasonality_options' => $seasonalOptions->audit(), 'cache_stale' => $cacheStale,
            'cache_calculated_at_min' => $source->summary['cache_calculated_at_min'] ?? null, 'cache_calculated_at_max' => $source->summary['cache_calculated_at_max'] ?? null,
            'training' => ['requested_from' => $source->from->format(DATE_ATOM), 'consecutive_from' => $train === [] ? null : $train[0]->from->format(DATE_ATOM),
                'consecutive_periods' => count($train), 'positive_periods' => $positive, 'months' => array_map(fn ($p): array => $p->audit(), $periods)],
            'seasonality_quality' => $seasonality['quality'] ?? 'insufficient', 'candidates' => array_map(fn ($m): string => $m->name(), $models),
            'forecasts' => $forecasts, 'backtesting' => $evaluation,
            'warnings' => ['Se pronostican ventas brutas observables; no demanda total ni consumo neto definitivo de inventario.',
                'Disponibilidad histórica y demanda perdida desconocidas; no se corrigen ventas por agotamientos supuestos.',
                'Las revisiones posteriores del ERP impiden simular exactamente lo conocido en cada fecha antigua.',
                'Los modelos se seleccionan antes de la reserva temporal; sus resultados reservados no cambian retrospectivamente la selección.',
                'Los límites predictivos y confidence permanecen NULL: no se calibraron intervalos de predicción.',
                'La precisión interna de doce decimales no representa precisión comercial; display_quantity sólo redondea la presentación.']]);
    }
    /**
     * Construye candidatos de complejidad creciente con parámetros fijos.
     * @param ForecastOptions $options Parámetros comparables.
     * @return array Métodos sin ajuste previo.
     */
    private function models(ForecastOptions $options): array
    {
        $models = [new StatisticalForecastModel('mean'), new StatisticalForecastModel('weighted')];
        foreach (array_unique($options->alphas) as $alpha) { $models[] = new StatisticalForecastModel('ses', $alpha); }
        return [...$models, new StatisticalForecastModel('trend'), new StatisticalForecastModel('seasonal'), new StatisticalForecastModel('tsb', '0.2')];
    }
    /**
     * Exige comparación pareada sobre los mismos objetivos y orígenes.
     * @param array|null $a Evaluación base.
     * @param array|null $b Evaluación candidata.
     * @return bool Verdadero si hay evidencia idéntica y no vacía.
     */
    private function sameOrigins(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null || $a['observations'] === [] || count($a['observations']) !== count($b['observations'])) { return false; }
        return array_map(fn ($r): string => $r['origin'].'/'.$r['target'], $a['observations']) === array_map(fn ($r): string => $r['origin'].'/'.$r['target'], $b['observations']);
    }
}
