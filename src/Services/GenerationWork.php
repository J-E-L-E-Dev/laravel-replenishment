<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\Models\{GenerationAttempt,Policy,ProductSupplier,ProposalGeneration,ProposalGenerationEvent,ProposalGenerationItem};
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Reserva un producto por generación, prepara fuera del bloqueo y confirma con versión y propietario. */
final class GenerationWork
{
    /** @param PersistenceConnection $own Destino propio. @param GenerationStatistics $statistics Preparación comercial.
     * @param PolicyDrivenPlanningService $planner Motor D1. @param PerformanceTrace $trace Diagnóstico optativo de la operación. */
    public function __construct(private PersistenceConnection $own, private GenerationStatistics $statistics,
        private PolicyDrivenPlanningService $planner, private PerformanceTrace $trace) {}

    /** Recupera la fecha civil original usando la zona nominal del consumidor.
     * @param ProposalGeneration $g Generación congelada. @return DateTimeImmutable Fecha operativa.
     * @throws LogicException Si ha cambiado el día o la zona operativa. */
    public function day(ProposalGeneration $g): DateTimeImmutable
    {
        $zone = config('app.timezone','UTC');
        $date = (new DateTimeImmutable($g->snapshot['as_of']))->setTimezone(new DateTimeZone($zone));
        if (($g->snapshot['timezone'] ?? $zone) !== $zone || $date != CarbonImmutable::now($zone)->startOfDay()) {
            throw new GenerationConflict('operating_day_changed','Cambió el día operativo. Cree una generación derivada para evaluar la selección con la fecha vigente.');
        }
        return $date;
    }

    /** Ejecuta el motor con política congelada y publicación fijada, también al revalidar una línea.
     * @param ProposalGeneration $g Generación. @param ProposalGenerationItem $item Producto. @param string $actor Usuario.
     * @param array|null $prepared Publicación y perfil originales. @return array Resultado D1 con su procedencia.
     * @throws GenerationFailure Si cambia el perfil o la unidad. */
    public function calculate(ProposalGeneration $g, ProposalGenerationItem $item, string $actor, ?array $prepared): array
    {
        $snapshot = $g->snapshot;
        if (!isset($snapshot['algorithm_version']) && (isset($snapshot['options']['manual_daily']) || isset($snapshot['options']['conservative_daily']))
            && ProposalGenerationItem::where('generation_id',$g->id)->count() > 1) {
            throw new GenerationFailure('configuration_required','La tasa manual histórica requiere una selección individual.');
        }
        $attributes = $snapshot['policy'];
        if (isset($attributes['demand_settings'])) {
            $attributes['demand_settings'] = json_encode($attributes['demand_settings'], JSON_THROW_ON_ERROR);
        }
        $policy = new Policy();
        $policy->setRawAttributes($attributes,true);
        $policy->exists = true;
        $pin = $prepared === null || $prepared['origin'] === 'legacy' ? null : ($prepared['sales_run_id'] ?? 0);
        $result = $this->planner->analyze(ProductSupplier::findOrFail($item->relation_id),$policy,$g->location_id,
            $this->day($g),$snapshot['options'],$actor,$pin,$prepared['legacy_fingerprint'] ?? null);
        if ($prepared !== null) {
            $window = $result['demand_evidence']['window'];
            if (isset($prepared['unit']) && $prepared['unit'] !== $result['base_unit']) {
                throw new GenerationFailure('unit_incompatible','Cambió la unidad de venta del producto.');
            }
            if (($window['profile_hash'] ?? null) !== ($prepared['profile_hash'] ?? null)
                || ($window['run_id'] ?? null) !== ($prepared['sales_run_id'] ?? null)) {
                throw new GenerationFailure('profile_incompatible','Cambió el perfil estadístico del producto.');
            }
            $result['statistics_preparation'] = $prepared;
        }
        return $result;
    }

    /** Procesa o revisa exactamente una línea. Cada UUID confirmado tiene un único efecto.
     * @param int $id Generación. @param int $version Versión esperada. @param string $action process, retry o recalculate.
     * @param array $input Identidad individual y confirmación. @param string $actor Usuario. @param string $request UUID.
     * @param string $hash Huella del comando. @return ProposalGeneration Estado confirmado.
     * @throws LogicException Ante conflicto, reserva activa o cambio de día. */
    public function execute(int $id,int $version,string $action,array $input,string $actor,string $request,string $hash): ProposalGeneration
    {
        return $this->trace->measure('generation_product',['generation_id'=>$id,'action'=>$action],
            fn () => $this->processItem($id,$version,$action,$input,$actor,$request,$hash));
    }

    /** Reserva y confirma una operación individual. @param int $id Generación. @param int $version Versión esperada.
     * @param string $action Comando. @param array $input Selección. @param string $actor Comprador.
     * @param string $request UUID. @param string $hash Huella. @return ProposalGeneration Ejecución confirmada.
     * @throws LogicException Ante incompatibilidad de versión, fecha o reserva. */
    private function processItem(int $id,int $version,string $action,array $input,string $actor,string $request,string $hash): ProposalGeneration
    {
        $claim = $this->own->transaction(function () use ($id,$version,$action,$input,$actor,$request,$hash) {
            $g = ProposalGeneration::query()->lockForUpdate()->findOrFail($id);
            if ($event = ProposalGenerationEvent::where('request_id',$request)->first()) {
                if ($event->request_hash !== $hash) {
                    throw new GenerationConflict('generation_command','UUID utilizado para otra operación.');
                }
                return [$g,null,null];
            }
            if ($g->version !== $version) {
                throw new GenerationConflict('version_mismatch','La versión de la generación cambió. Recargue su estado.');
            }
            if (!in_array($g->status,['processing','ready','saving'],true)) {
                throw new GenerationConflict('generation_closed','La generación ya no admite procesamiento.');
            }
            $this->day($g);
            $priorHash = GenerationAttempt::where('command_id',$request)->value('command_hash');
            if ($priorHash !== null && $priorHash !== $hash) {
                throw new GenerationConflict('generation_command','El UUID pertenece a otro intento de procesamiento.');
            }
            $rows = ProposalGenerationItem::where('generation_id',$id);
            $held = (clone $rows)->whereNotNull('lease_token')->lockForUpdate()->first();
            if ($held && CarbonImmutable::parse($held->getRawOriginal('lease_until'),'UTC') > CarbonImmutable::now('UTC')) {
                throw new GenerationConflict('lease_active','El producto '.$held->product_id.' está en procesamiento con una reserva activa. Consulte el progreso antes de reanudar.');
            }
            if ($action === 'process') {
                if ($g->status !== 'processing') {
                    throw new GenerationConflict('generation_closed','La generación ya terminó su procesamiento.');
                }
                $row = $rows->where('status','pending')->orderBy('id')->lockForUpdate()->firstOrFail();
            } else {
                if (($input['confirmed'] ?? false) !== true) {
                    throw new InvalidArgumentException('Confirme el nuevo cálculo y la revisión de la decisión anterior.');
                }
                $row = $rows->lockForUpdate()->findOrFail($input['item_id'] ?? 0);
                if ($row->saved || $row->status === 'pending' || ($action === 'retry' && $row->technical_state !== 'recoverable_error' && !$held)) {
                    throw new GenerationConflict('generation_command','La línea no admite esta operación. Para una línea publicada use una generación derivada.');
                }
            }
            $streak = 1;
            $mode = $action === 'recalculate' ? 'force_refresh' : ($g->snapshot['statistics_mode'] ?? 'cache_only');
            if ($held) {
                $expired = GenerationAttempt::where('token',$held->lease_token)->firstOrFail();
                if ($held->id !== $row->id || $expired->action !== $action || $expired->generation_version !== $version
                    || ($expired->command_id === $request && $expired->command_hash !== $hash)) {
                    throw new GenerationConflict('recovery_required','Recupere primero la operación interrumpida del producto '.$held->product_id.'.');
                }
                $mode = $expired->evidence['mode'] ?? $mode;
                $expired->evidence = $expired->evidence + ['interrupted_stage'=>$expired->stage,
                    'lease_until_utc'=>$held->getRawOriginal('lease_until')];
                $expired->stage = 'expired';
                $expired->save();
                $streak = ($expired->evidence['streak'] ?? 1) + 1;
            } elseif ($action === 'recalculate') {
                $row->preparation = null;
                $row->statistics_origin = null;
            } elseif ($action === 'retry') {
                $last = GenerationAttempt::where('item_id',$row->id)->latest('id')->first();
                $mode = $last?->evidence['mode'] ?? $mode;
                $streak = ($last?->evidence['streak'] ?? 0) + 1;
            }
            $row->lease_token = (string)Str::uuid();
            $row->lease_until = CarbonImmutable::now('UTC')->addMinutes(10);
            $row->technical_state = 'preparing';
            $row->attempts++;
            $row->save();
            $attempt = GenerationAttempt::create(['item_id'=>$row->id,'token'=>$row->lease_token,'command_id'=>$request,
                'command_hash'=>$hash,'action'=>$action,'actor_id'=>$actor,'generation_version'=>$version,
                'stage'=>'preparing','evidence'=>['streak'=>$streak,'mode'=>$mode]]);
            return [$g,$row,$attempt];
        });
        [$g,$row,$attempt] = $claim;
        if ($row === null) {
            return $g;
        }
        $this->trace->tag(['product'=>$row->product_id,'attempt_id'=>$attempt->id]);
        $stage = 'preparing';
        $failure = null;
        try {
            if ($attempt->evidence['streak'] > 3) {
                throw new GenerationFailure('repeated_interruptions','Tres intentos previos quedaron sin completar. Revise el producto antes de autorizar otro cálculo.');
            }
            $mode = $attempt->evidence['mode'];
            $prepared = $this->statistics->prepare($g,$row,$this->day($g),$mode,function (array $data) use ($g,$attempt): void {
                $this->own->transaction(function () use ($g,$attempt,$data): void {
                    [, $item] = $this->owned($g,$attempt);
                    $item->preparation = $data;
                    $item->statistics_origin = $data['legacy_action'] ?? $data['origin'];
                    $item->technical_state = 'calculating';
                    $item->lease_until = CarbonImmutable::now('UTC')->addMinutes(10);
                    $item->save();
                    $attempt->stage = 'calculating';
                    $attempt->save();
                });
            });
            $stage = 'calculating';
            $this->trace->tag(['sales_run_id'=>$prepared['sales_run_id'] ?? null]);
            $result = $this->trace->measure('planning',['sales_run_id'=>$prepared['sales_run_id'] ?? null],
                fn () => $this->calculate($g,$row,$actor,$prepared));
        } catch (\Throwable $error) {
            $failure = GenerationFailure::describe($error);
            $this->trace->tag(['diagnosis'=>$failure->reason,'failed_stage'=>$stage]);
            $result = ['status'=>'error','stage'=>$stage,'diagnosis'=>$failure->reason,'message'=>$failure->getMessage()];
        }
        return $this->own->transaction(function () use ($g,$attempt,$result,$failure,$stage,$request,$hash,$actor,$action) {
            [$generation,$item] = $this->owned($g,$attempt);
            $this->day($generation);
            $previous = $item->result;
            $archive = [];
            if ($previous !== null) {
                $archive = ['previous_result'=>$previous,'previous_decision'=>$item->decision];
                if ($item->decision !== null || $item->previous_choice === null) {
                    $item->previous_choice = self::comparison($previous,$item->decision);
                }
                $item->revised_result = $result;
                $item->decision = null;
            } else {
                $item->result = $result;
            }
            $item->technical_state = $failure === null ? 'processed'
                : ($failure->retryable && $attempt->evidence['streak'] < 3 ? 'recoverable_error' : 'intervention_required');
            $item->status = $result['status'];
            $item->row_summary = self::rowSummary($result);
            $window = $result['demand_evidence']['window'] ?? [];
            $item->diagnosis = $failure?->reason ?? (($item->preparation['legacy_action'] ?? null)==='missing' ? 'refresh_required' : ($window['status'] ?? null));
            if ($failure === null && $item->diagnosis !== 'refresh_required') {
                $item->diagnosis = match (true) {
                    $item->status === 'configuration_required' => 'configuration_required',
                    ($result['demand_after_receipt']['quantity'] ?? null) === null && ($window['incompatible_days'] ?? 0) > 0 => 'unit_incompatible',
                    ($result['demand_after_receipt']['quantity'] ?? null) === null => 'insufficient_history',
                    default => null,
                };
            }
            $item->requires_review = $item->status === 'declining_demand_review'
                || ($result['extended_coverage']['classification'] ?? null) === 'scenario_conflict'
                || ($result['extended_coverage']['requires_commercial_review'] ?? false);
            $quantity = $result['packages']['packages'] ?? null;
            $item->bulk_candidate = match (true) {
                $quantity === null || $item->requires_review || $item->diagnosis !== null => null,
                $item->status === 'purchase_needed' && BigDecimal::of($quantity)->isPositive() => 'accept',
                in_array($item->status,['no_purchase_needed','extended_coverage_candidate'],true) && BigDecimal::of($quantity)->isZero() => 'exclude_zero',
                default => null,
            };
            $item->lease_token = $item->lease_until = null;
            $item->save();
            $attempt->stage = $item->technical_state;
            $attempt->evidence = $attempt->evidence + $archive + ['diagnosis'=>$item->diagnosis,'failed_stage'=>$failure ? $stage : null,
                'preparation'=>$item->preparation,'comparison'=>self::comparison($result,null)];
            $attempt->save();
            if ($generation->status === 'processing' && !ProposalGenerationItem::where('generation_id',$generation->id)->where('status','pending')->exists()) {
                $generation->status = 'ready';
            }
            $generation->version++;
            $generation->save();
            ProposalGenerationEvent::create(['generation_id'=>$generation->id,'request_id'=>$request,'request_hash'=>$hash,
                'actor_id'=>$actor,'action'=>$action,'version'=>$generation->version,'payload'=>['item_id'=>$item->id,'attempt_id'=>$attempt->id,'status'=>$item->status]]);
            return $generation;
        });
    }

    /** Comprueba versión y token dentro de la transacción antes de vincular o confirmar evidencias.
     * @param ProposalGeneration $original Versión reservada. @param GenerationAttempt $attempt Propietario.
     * @return array Generación y línea bloqueadas. @throws LogicException Si otro intento recuperó la unidad. */
    private function owned(ProposalGeneration $original, GenerationAttempt $attempt): array
    {
        $g = ProposalGeneration::query()->lockForUpdate()->findOrFail($original->id);
        $item = ProposalGenerationItem::where('generation_id',$g->id)->lockForUpdate()->findOrFail($attempt->item_id);
        if ($g->version !== $attempt->generation_version || $item->lease_token !== $attempt->token
            || !in_array($item->technical_state,['preparing','calculating'],true)) {
            throw new GenerationConflict('recovery_required','La reserva del producto cambió. Consulte la ejecución.');
        }
        return [$g,$item];
    }

    /** Reduce la evidencia a los campos utilizados por la tabla operativa. @param array $result Cálculo completo.
     * @return array Resumen sin series históricas para paginar miles de productos. */
    public static function rowSummary(array $result): array
    {
        $summary = array_intersect_key($result,array_flip(['status','product_name','base_unit','protection_days','cost','packages','presentation','warnings','message']));
        foreach (['availability'=>['available'],'trend'=>['classification'],'lead_time'=>['lead_time_days'],
            'policy'=>['target_coverage_days'],'demand_after_receipt'=>['quantity'],'balance'=>['required'],
            'extended_coverage'=>['days','assessment_version','classification','candidate','central_sufficient','conservative_source',
                'conservative_sufficient','incoming_source','warning','warnings','requires_commercial_review']] as $key=>$fields) {
            $summary[$key] = array_intersect_key($result[$key] ?? [],array_flip($fields));
        }
        return $summary;
    }

    /** Conserva los importes y la decisión anterior para su revisión. @param array $result Evidencia.
     * @param array|null $decision Decisión humana previa. @return array Valores anteriores sin aprobación implícita. */
    public static function comparison(array $result, ?array $decision): array
    {
        return ['suggested'=>$result['packages']['packages'] ?? null,'cost'=>$result['cost'] ?? null,
            'coverage'=>$result['extended_coverage']['classification'] ?? null,'status'=>$result['status'] ?? null,'decision'=>$decision];
    }
}
