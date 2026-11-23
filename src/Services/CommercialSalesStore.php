<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\{CommercialSalesData,ProductData};
use Asisnet\Replenishment\Models\{CommercialSalesDay,CommercialSalesRun,CommercialSalesUnit};
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Materializa ejecuciones inmutables y selecciona una sola publicación compatible para todo el intervalo. */
final class CommercialSalesStore
{
    /** @param PersistenceConnection $own Destino propio. @param CommercialSalesQuery $query Lectura fuera de transacción.
     * @param CommercialProfile $profiles Reglas semánticas. @param ObservedDemandCalculator $calendar Límites civiles.
     * @param CommercialSalesDetails $details Escritor de detalles validados. @param PerformanceTrace $trace Diagnóstico optativo. */
    public function __construct(private PersistenceConnection $own, private CommercialSalesQuery $query,
        private CommercialProfile $profiles, private ObservedDemandCalculator $calendar, private CommercialSalesDetails $details, private PerformanceTrace $trace) {}

    /**
     * Publica una lectura terminada en una transacción. Un fallo deja consultables todas las ejecuciones previas.
     * @param string $product Producto. @param string $location Destino. @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin exclusivo. @param string $timezone Zona civil.
     * @param callable|null $onPublished Vincula el identificador dentro de la misma transacción. Una excepción revierte la publicación.
     * @return CommercialSalesData Resultado con identificador persistido.
     * @throws RuntimeException Si hay incidencias bloqueantes o falla la publicación.
     */
    public function materialize(string $product, string $location, DateTimeImmutable $from, DateTimeImmutable $until, string $timezone, ?callable $onPublished = null): CommercialSalesData
    {
        $context = ['product'=>$product];
        $data = $this->trace->measure('commercial_read', $context,
            fn () => $this->query->read($product, $location, $from, $until, $timezone));
        if ($data->status !== 'complete') {
            throw match ($data->limitation) {
                'source_timeout' => new GenerationFailure('timeout','La lectura comercial excedió el tiempo de espera.',true),
                'source_connection_failed' => new GenerationFailure('connection_error','La conexión comercial no está disponible.',true),
                'commercial_source_failed' => new GenerationFailure('source_unavailable','Lectura comercial incompleta. Compruebe la disponibilidad de la fuente.',true),
                default => new GenerationFailure('incomplete_publication','La lectura comercial contiene incidencias. Revise el perfil y los movimientos.'),
            };
        }
        $commit = null;
        try {
            $id = $this->own->transaction(function () use ($data, $product, $location, $onPublished, $context, &$commit): int {
                $run = $this->trace->measure('insert_header',$context + ['records'=>1,'batches'=>1],fn () => CommercialSalesRun::create(['product_id' => $product, 'location_id' => $location,
                    'profile' => $data->profile, 'profile_hash' => CommercialProfile::hash($data->profile),
                    'scope_hash' => $this->scopeHash($data->profile), 'status' => 'complete',
                    'requested_start' => $data->from->format('Y-m-d'), 'effective_start' => $data->effective->format('Y-m-d'),
                    'until_date' => $data->until->format('Y-m-d'), 'calculated_at' => $data->calculatedAt]));
                $this->trace->measure('publication_details',$context + ['sales_run_id'=>$run->id],
                    fn () => $this->details->insert((int)$run->id,$data));
                if ($onPublished !== null) {
                    $this->trace->measure('on_published',$context + ['sales_run_id'=>$run->id],
                        fn () => $onPublished((int)$run->id,$data));
                }
                $commit = [hrtime(true),(int)$run->id];
                return $run->id;
            });
        } finally {
            if ($commit !== null) {
                $this->trace->record('commit',$commit[0],isset($id)?'completo':'fallo',$context + ['sales_run_id'=>$commit[1]]);
            }
        }
        return new CommercialSalesData($data->profile, $data->from, $data->effective, $data->until,
            $data->calculatedAt, $data->days, $data->status, 'source', $id);
    }

    /** Conserva en la huella local los parámetros que no necesitan verificar el maestro externo.
     * @param array $profile Perfil completo. @return string Huella de ámbito, nunca autorización para mezclar resultados. */
    private function scopeHash(array $profile): string
    {
        unset($profile['cutoff'], $profile['current_unit']);
        return CommercialProfile::hash($profile);
    }

    /**
     * Consulta sólo tablas propias. Para utilizar datos actuales se requiere ficha independiente; el modo fotográfico es explícito.
     * @param string $product Producto. @param string $location Destino. @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin exclusivo. @param string $timezone Zona. @param ProductData|null $master Ficha verificada para el cálculo.
     * @param bool $snapshot Permite leer una fotografía histórica sin acreditar la vigencia actual del maestro.
     * @param int|null $runId Ejecución fijada por un cálculo que ya seleccionó perfil.
     * @return CommercialSalesData Ejecución única o actualización necesaria.
     */
    public function cached(string $product, string $location, DateTimeImmutable $from, DateTimeImmutable $until, string $timezone,
        ?ProductData $master = null, bool $snapshot = false, ?int $runId = null): CommercialSalesData
    {
        [$from, $until] = $this->calendar->bounds($from, $until, $timezone);
        $profile = $this->profiles->describe($product, $location, $timezone, $master?->stockUnit, $master?->statisticsCutoffDate);
        $run = $this->compatibleRun($profile, $from, $until, $master, $snapshot, $runId);
        if ($run !== null) {
            return $this->restore($run, $from, $until, $master === null ? 'master_not_rechecked' : null);
        }

        $effective = $master?->statisticsCutoffDate === null ? $from : min($until,max($from,
            (new DateTimeImmutable($master->statisticsCutoffDate, $from->getTimezone()))->modify('+1 day')));
        return new CommercialSalesData($profile, $from, $effective, $until, new DateTimeImmutable('now', new DateTimeZone('UTC')),
            CommercialSalesQuery::calendarDays($from, $effective, $until, 'unknown'), 'refresh_required', 'cache',
            limitation: $master === null && !$snapshot ? 'current_profile_unverified' : 'compatible_execution_missing');
    }

    /** Localiza la cabecera compatible sin cargar el calendario. La lectura fijada valida después todos los días.
     * @param array $profile Perfil actual. @param DateTimeImmutable $from Inicio efectivo. @param DateTimeImmutable $until Fin exclusivo.
     * @param ProductData|null $master Ficha actual. @param bool $snapshot Consulta histórica. @param int|null $runId Identidad fijada.
     * @return CommercialSalesRun|null Publicación completa que cubre todo el intervalo. */
    public function compatibleRun(array $profile, DateTimeImmutable $from, DateTimeImmutable $until, ?ProductData $master,
        bool $snapshot = false, ?int $runId = null): ?CommercialSalesRun
    {
        $product = $profile['product'];
        $location = $profile['location'];
        $run = null;
        if (($master?->id === $product || ($snapshot && $master === null))
            && $this->own->connection()->getSchemaBuilder()->hasTable('replenishment_sales_runs')) {
            $query = CommercialSalesRun::where('product_id', $product)->where('location_id', $location)
                ->where('scope_hash', $this->scopeHash($profile))->where('status','complete')
                ->orderByDesc('calculated_at')->orderByDesc('id');
            if ($runId !== null) {
                $query->whereKey($runId);
            }
            $hash = $master !== null ? CommercialProfile::hash($profile) : (clone $query)->value('profile_hash');
            $run = $query->where('profile_hash', $hash)->where('requested_start', '<=', $from->format('Y-m-d'))
                ->where('until_date', '>=', $until->format('Y-m-d'))->first();
        }
        return $run;
    }

    /** Lee un intervalo de una versión histórica dentro del producto y destino autorizados.
     * @param int $id Publicación elegida. @param string $product Identidad exacta. @param string $location Destino autorizado.
     * @param DateTimeImmutable $from Inicio inclusivo. @param DateTimeImmutable $until Fin exclusivo.
     * @return CommercialSalesData Observaciones originales con días desconocidos fuera de la publicación.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la publicación pertenece a otro ámbito.
     * @throws \InvalidArgumentException Ante una zona o intervalo incompatible. */
    public function historicalWindow(int $id,string $product,string $location,DateTimeImmutable $from,DateTimeImmutable $until): CommercialSalesData
    {
        $run=CommercialSalesRun::where('product_id',$product)->where('location_id',$location)->findOrFail($id);
        $zone=$from->getTimezone();
        if ($run->profile['timezone']!==$zone->getName() || $from >= $until) {
            throw new \InvalidArgumentException('El período o la zona operativa difiere de la publicación.');
        }
        $start=max($from,new DateTimeImmutable($run->requested_start,$zone));
        $stop=min($until,new DateTimeImmutable($run->until_date,$zone));
        $cutoff=$run->profile['cutoff'] ?? null;
        $effective=$cutoff===null?$from:min($until,max($from,(new DateTimeImmutable($cutoff,$zone))->modify('+1 day')));
        $days=CommercialSalesQuery::calendarDays($from,$effective,$until,'unknown');
        if ($start<$stop) {
            $days=array_replace($days,$this->restore($run,$start,$stop,'historical_snapshot')->days);
        }
        return new CommercialSalesData($run->profile,$from,$effective,$until,
            new DateTimeImmutable($run->getRawOriginal('calculated_at'),new DateTimeZone('UTC')),$days,
            in_array('unknown',array_column($days,'state'),true)?'incomplete':'complete','cache',(int)$run->id,'historical_snapshot');
    }

    /** Recupera una publicación histórica por identidad exacta sin resolver el perfil vigente.
     * @param int $id Identidad de publicación. @return CommercialSalesData Fotografía original. @throws RuntimeException Ante evidencia corrupta o ausente. */
    public function execution(int $id): CommercialSalesData
    {
        $run = CommercialSalesRun::findOrFail($id);
        $zone = new DateTimeZone($run->profile['timezone']);
        return $this->restore($run, new DateTimeImmutable($run->requested_start, $zone),
            new DateTimeImmutable($run->until_date, $zone), 'historical_snapshot');
    }

    /** Lee cobertura y componentes por ejecución sin consultas por día.
     * @param CommercialSalesRun $run Fotografía seleccionada. @param DateTimeImmutable $from Inicio contenido.
     * @param DateTimeImmutable $until Fin contenido. @param string|null $limitation Advertencia de vigencia.
     * @return CommercialSalesData Serie reconstruida. @throws RuntimeException Ante historial incompleto o incompatible. */
    private function restore(CommercialSalesRun $run, DateTimeImmutable $from, DateTimeImmutable $until, ?string $limitation): CommercialSalesData
    {
        if ($run->status !== 'complete' || CommercialProfile::hash($run->profile) !== $run->profile_hash
            || $run->product_id !== $run->profile['product'] || $run->location_id !== $run->profile['location']) {
            throw new RuntimeException('La identidad de la publicación comercial es inconsistente.');
        }
        $days = [];
        $counts = [];
        $dayQuery = CommercialSalesDay::where('run_id', $run->id)->whereBetween('sales_date', [$from->format('Y-m-d'), $until->modify('-1 day')->format('Y-m-d')]);
        foreach ((clone $dayQuery)->orderBy('sales_date')->cursor() as $row) {
            $days[$row->sales_date] = ['state' => $row->state, 'event_count' => $row->event_count, 'issues' => $row->issues, 'units' => []];
            $counts[$row->id] = $row->sales_date;
        }
        if (count($days) !== $from->diff($until)->days) {
            throw new RuntimeException('Falta cobertura diaria en la ejecución seleccionada.');
        }
        foreach (CommercialSalesUnit::whereIn('day_id', $dayQuery->select('id'))->orderBy('id')->cursor() as $unit) {
            if (!hash_equals($unit->unit_hash, hash('sha256', $unit->unit))) {
                throw new RuntimeException('Unidad histórica inconsistente en la publicación.');
            }
            $bucket = $unit->only([...ObservedDemandCalculator::QUANTITIES, 'unit', 'event_count']);
            $days[$counts[$unit->day_id]]['units'][$unit->unit] = $bucket;
        }
        foreach ($days as $key => $day) {
            $expected = $key < $run->effective_start ? 'outside_cutoff'
                : ($day['units'] === [] ? 'zero_observed' : 'complete');
            $events = $expected === 'outside_cutoff' ? null : array_sum(array_column($day['units'], 'event_count'));
            if ($expected !== $day['state'] || $day['event_count'] !== $events) {
                throw new RuntimeException('Los agregados por unidad no concuerdan con la cobertura publicada.');
            }
        }
        $effective = min($until,max($from, new DateTimeImmutable($run->effective_start, $from->getTimezone())));
        return new CommercialSalesData($run->profile, $from, $effective, $until, new DateTimeImmutable($run->getRawOriginal('calculated_at'), new DateTimeZone('UTC')),
            $days, 'complete', 'cache', $run->id, $limitation);
    }
}
