<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\ObservedDemandData;
use Asisnet\Replenishment\DTO\ProductData;
use DateTimeImmutable;

/** Prepara una proyección privada para planificar, conservando la fotografía comercial original. */
final class PlanningHistory
{
    /** @param ObservedDemandStore $cache Caché compatible con consumidores anteriores.
     * @param CommercialProfile $profile Selector de fuente. @param ObservedDemandCalculator $calendar Consolidador civil. */
    public function __construct(private ObservedDemandStore $cache, private CommercialProfile $profile,
        private ObservedDemandCalculator $calendar) {}

    /** Lee una sola publicación materializada en el intervalo efectivo y aplica exclusivamente el consentimiento congelado.
     * @param ProductData $product Maestro actual. @param string $location Destino lógico.
     * @param DateTimeImmutable $from Inicio solicitado. @param DateTimeImmutable $until Corte exclusivo.
     * @param array $settings Parámetros validados. @param string|null $author Responsable del consentimiento.
     * @param int|null $runId Publicación fijada, cero exige ausencia de publicación y NULL conserva la selección de D1.
     * @param string|null $legacyFingerprint Contenido diario previamente preparado con SaleSource.
     * @throws GenerationFailure Si cambia la caché heredada vinculada.
     * @return array Serie de planificación y evidencia de los límites aplicados. */
    public function read(ProductData $product, string $location, DateTimeImmutable $from, DateTimeImmutable $until,
        array $settings, ?string $author, ?int $runId = null, ?string $legacyFingerprint = null): array
    {
        $effective = $from;
        if ($this->profile->enabled() && $product->statisticsCutoffDate !== null) {
            $cutoff = new DateTimeImmutable($product->statisticsCutoffDate, $from->getTimezone());
            $effective = min($until, max($from, $cutoff->modify('+1 day')));
        }
        $series = $this->cache->read($product->id, $location, $effective < $until ? $effective : $from, $until,
            $until->getTimezone()->getName(), $product, runId: $runId);
        if ($legacyFingerprint !== null && ($series->statistics || self::legacyFingerprint($series) !== $legacyFingerprint)) {
            throw new GenerationFailure('profile_incompatible','Cambió la caché diaria preparada. Actualice y recalcule este producto.');
        }
        return $this->project($series, $from, $effective, $product->stockUnit, $settings, $author);
    }

    /** Identifica el contenido diario de la caché anterior a las publicaciones versionadas.
     * @param ObservedDemandData $series Intervalo completo con días e incidencias explícitas.
     * @return string Huella de la señal original, independiente del instante de lectura. */
    public static function legacyFingerprint(ObservedDemandData $series): string
    {
        $digest = hash_init('sha256');
        $fields = [...ObservedDemandCalculator::QUANTITIES,'base_unit','event_count','observation_status','quality_issues','inventory_quality_issues'];
        foreach ($series->days as $date=>$day) {
            $values = [$date];
            foreach ($fields as $field) {
                $values[] = $day[$field] ?? null;
            }
            hash_update($digest,json_encode($values,JSON_THROW_ON_ERROR));
        }
        return hash_final($digest);
    }

    /** Delimita el sufijo posterior al último movimiento incompatible sólo para estrategias de bloques recientes.
     * @param ObservedDemandData $raw Proyección estricta de C o serie anterior.
     * @param DateTimeImmutable $requested Inicio solicitado. @param DateTimeImmutable $effective Inicio tras el corte.
     * @param string $unit Unidad vigente. @param array $settings Política congelada. @param string|null $author Responsable.
     * @return array Serie privada y evidencia reproducible, sin mutar la ejecución. */
    public function project(ObservedDemandData $raw, DateTimeImmutable $requested, DateTimeImmutable $effective,
        string $unit, array $settings, ?string $author): array
    {
        $days = $raw->days;
        $start = $effective;
        $incompatible = $unknown = $zeros = 0;
        $assumedDays = 0;
        $stats = $raw->statistics;
        $verified = ($stats['status'] ?? null) === 'complete' && isset($stats['run_id'])
            && ($stats['profile']['current_unit'] ?? null) === $unit && $raw->version === CommercialProfile::VERSION;
        foreach ($days as $key => $day) {
            if (!$stats && $day['observation_status'] === 'complete' && $day['base_unit'] !== $unit) {
                $days[$key]['observation_status'] = 'incomplete';
                $days[$key]['quality_issues'][] = 'historical_reference_unit_unknown';
                $unknown++;
                continue;
            }
            if ($key < $effective->format('Y-m-d') || $day['observation_status'] === 'complete'
                && $day['base_unit'] === $unit) {
                continue;
            }
            if ($day['quality_issues'] === ['historical_reference_unit_unknown']) {
                $zeros++;
            } elseif (in_array('incompatible_unit', $day['quality_issues'], true)) {
                $incompatible++;
                if ($settings['strategy'] !== 'historical_average') {
                    $start = (new DateTimeImmutable($key, $effective->getTimezone()))->modify('+1 day');
                }
            } else {
                $unknown++;
            }
        }
        // Un hueco de lectura nunca puede desaparecer al recortar un cambio de unidad.
        if ($unknown > 0) {
            $start = $effective;
        }
        $usableDays = array_filter($days, fn ($date) => $date >= $start->format('Y-m-d'), ARRAY_FILTER_USE_KEY);
        if ($verified && $settings['continuity'] === 'assumed' && trim($author ?? '') !== '' && $unknown === 0
            && ($incompatible === 0 || $start > $effective)) {
            foreach ($usableDays as &$day) {
                if ($day['observation_status'] !== 'complete' && $day['quality_issues'] === ['historical_reference_unit_unknown']) {
                    $day = array_replace($day, array_fill_keys(ObservedDemandCalculator::QUANTITIES, '0'),
                        ['event_count'=>0, 'observation_status'=>'complete', 'base_unit'=>$unit, 'quality_issues'=>[]]);
                    $assumedDays++;
                }
            }
            unset($day);
        }
        if ($stats) {
            $stats['planning_window'] = [
                'requested_start'=>$requested->format('Y-m-d'), 'effective_start'=>$effective->format('Y-m-d'),
                'segment_start'=>$start->format('Y-m-d'), 'until'=>$raw->until->format('Y-m-d'),
            ];
        }
        $series = $this->calendar->fromDays($raw->productId, $raw->locationId, $start < $raw->until ? $start : $raw->from,
            $raw->until, $raw->timezone, $usableDays, $raw->origin, $raw->version, $stats, $raw->calculatedAt);
        return [$series, [
            'requested_start'=>$requested->format('Y-m-d'), 'effective_start'=>$effective->format('Y-m-d'),
            'segment_start'=>$start->format('Y-m-d'), 'until'=>$raw->until->format('Y-m-d'),
            'effective_days'=>$effective->diff($raw->until)->days, 'excluded_by_cutoff'=>$requested->diff($effective)->days,
            'excluded_before_segment'=>$effective->diff($start)->days, 'incompatible_days'=>$incompatible,
            'unknown_days'=>$unknown, 'comparable_days'=>$series->summary['observed_days'], 'zero_observed_days'=>$zeros,
            'assumed_days'=>$assumedDays, 'continuity'=>$settings['continuity'], 'authorized_by'=>$author,
            'unit'=>$unit, 'projection_version'=>'planning-continuity-1', 'run_id'=>$stats['run_id'] ?? null,
            'profile_hash'=>$stats['profile_hash'] ?? null,
            'status'=>$effective == $raw->until ? 'outside_cutoff' : ($stats['status'] ?? $series->summary['observation_status']),
        ]];
    }
}
