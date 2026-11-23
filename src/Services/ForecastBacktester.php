<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\ForecastOptions;
use Asisnet\Replenishment\DTO\SeasonalityOptions;

/** Validación expansiva con selección anterior y reserva final separada por horizonte. */
final class ForecastBacktester
{
    /**
     * Recibe preparación y métricas sin conexiones externas.
     * @param ForecastTraining $training Preparador de cortes.
     * @param ForecastEvaluator $evaluator Métricas exactas.
     */
    public function __construct(private ForecastTraining $training, private ForecastEvaluator $evaluator) {}
    /**
     * Mantiene los objetivos de selección anteriores al primer origen de validación, incluso a seis meses.
     * @param ObservedDemandData $source Serie completa disponible al corte final.
     * @param array $periods Meses con desconocidos explícitos.
     * @param array $models Candidatos con parámetros fijos.
     * @param ForecastOptions $options Ventanas y reserva.
     * @param SeasonalityOptions $seasonalOptions Criterios estacionales dentro de cada entrenamiento.
     * @return array Evaluación por horizonte, candidato y partición; no selecciona usando la reserva.
     */
    public function run(ObservedDemandData $source, array $periods, array $models, ForecastOptions $options, SeasonalityOptions $seasonalOptions): array
    {
        $result = []; $contexts = []; $n = count($periods); $horizons = array_values(array_unique(array_merge([1,2,3,6], range(1, $options->horizon)))); sort($horizons);
        foreach ($horizons as $horizon) {
            $origins = $n - $horizon >= $options->minimumTraining ? range($options->minimumTraining, $n - $horizon) : [];
            $origins = array_slice($origins, -$options->maximumOrigins);
            $validation = array_slice($origins, -$options->validationOrigins); $firstValidation = $validation[0] ?? null;
            $selection = array_values(array_filter($origins, fn (int $i): bool => $firstValidation !== null && $i + $horizon <= $firstValidation));
            // La comparación usa los mismos últimos orígenes prefijados, no el subconjunto más favorable de cada método.
            $selection = array_slice($selection, -$options->minimumSelection);
            $result[$horizon] = ['validation_first_origin' => $firstValidation === null ? null : $periods[$firstValidation]->from->format('Y-m-d'), 'candidates' => []];
            foreach ($models as $model) {
                $parts = [];
                foreach (['selection' => $selection, 'validation' => $validation] as $part => $indices) {
                    $observations = []; $excluded = [];
                    foreach ($indices as $i) {
                        $origin = $periods[$i]->from; $target = $periods[$i + $horizon - 1];
                        if (!isset($contexts[$i])) {
                            $train = $this->training->suffix(array_slice($periods, 0, $i));
                            $contexts[$i] = ['training' => $train, 'seasonality' => $this->training->seasonal($source, $train, $seasonalOptions),
                                'scale' => $this->evaluator->scale(array_slice($train, -$options->window))];
                        }
                        $context = $contexts[$i];
                        $prediction = $model->predict($context['training'], $origin, $horizon, $options, $context['seasonality']);
                        $identity = ['origin' => $origin->format('Y-m-d'), 'target' => $target->from->format('Y-m-d')];
                        $trainingUnits = array_values(array_unique(array_filter(array_map(fn ($p) => $p->unit, $context['training']), fn ($unit): bool => $unit !== null)));
                        $unitMismatch = $target->unit !== null && $trainingUnits !== [] && !in_array($target->unit, $trainingUnits, true);
                        if ($target->quantity === null || $prediction === null || $unitMismatch) {
                            $excluded[] = $identity + ['reason' => $target->quantity === null ? 'unknown_actual' : ($unitMismatch ? 'incompatible_actual_unit' : 'model_or_training_ineligible')]; continue;
                        }
                        $effective = array_slice($context['training'], -$options->window);
                        $observations[] = $identity + ['training_from' => $effective[0]->from->format('Y-m-d'), 'training_until' => $origin->format('Y-m-d'),
                            'seasonality_training_from' => $model->name() === 'seasonal' ? $context['training'][0]->from->format('Y-m-d') : null,
                            'predicted' => $prediction['quantity'], 'actual' => $target->quantity, 'mase_scale' => $context['scale']];
                    }
                    $parts[$part] = $this->evaluator->evaluate($observations, $excluded)->audit();
                }
                $result[$horizon]['candidates'][$model->name()] = $parts;
            }
        }
        return $result;
    }
}
