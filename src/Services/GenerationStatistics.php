<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\CommercialSalesData;
use Asisnet\Replenishment\Models\ProposalGeneration;
use Asisnet\Replenishment\Models\ProposalGenerationItem;
use DateTimeImmutable;

/** Selecciona o materializa la publicación exacta de un producto según la intención congelada. */
final class GenerationStatistics
{
    public const MODES = ['reuse_or_refresh_missing','force_refresh','cache_only'];

    /** @param CommercialSalesStore $store Publicaciones propias. @param AdapterRegistry $adapters Ficha actual.
     * @param CommercialProfile $profiles Identidad semántica de las estadísticas. @param ObservedDemandStore $legacyCache Caché diaria anterior.
     * @param ObservedDemandQuery $legacySource Proyección anterior. @param PersistenceConnection $own Destino de publicación. */
    public function __construct(private CommercialSalesStore $store, private AdapterRegistry $adapters, private CommercialProfile $profiles,
        private ObservedDemandStore $legacyCache, private ObservedDemandQuery $legacySource, private PersistenceConnection $own) {}

    /** Conserva el contrato SaleSource y su caché diaria. Las versiones antiguas carecen de identificador de publicación comercial.
     * @param ProposalGeneration $g Generación. @param ProposalGenerationItem $item Producto. @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin. @param array $base Ámbito. @param string $mode Intención. @param callable $checkpoint Reserva.
     * @return array Identidad de métrica y fecha cuando hubo reconstrucción. @throws GenerationFailure Ante cobertura incompleta. */
    private function legacy(ProposalGeneration $g,ProposalGenerationItem $item,DateTimeImmutable $from,DateTimeImmutable $until,
        array $base,string $mode,callable $checkpoint): array
    {
        $data=$base+['origin'=>'legacy','sales_run_id'=>null,'materialized_at'=>null,'statistics_version'=>ObservedDemandCalculator::VERSION];
        $cached=$this->legacyCache->read($item->product_id,$g->location_id,$from,$until,$base['timezone']);
        $fingerprint=PlanningHistory::legacyFingerprint($cached);
        $complete=$cached->summary['observation_status']==='complete';
        foreach ($cached->days as $day) {
            if ($day['base_unit'] !== null && $day['base_unit'] !== $base['unit']) {
                $complete=false;
                break;
            }
        }
        $old=$item->preparation;
        if ($complete && isset($old['legacy_metric_id']) && ($old['legacy_fingerprint'] ?? null)===$fingerprint) {
            $checkpoint($old);
            return $old;
        }
        if ($mode==='cache_only' || $complete && $mode!=='force_refresh') {
            $data['materialized_at']=$cached->summary['cache_calculated_at_min'];
            $data['legacy_action']=$complete?'reused':'missing';
            $data['legacy_fingerprint']=$fingerprint;
            $checkpoint($data);
            return $data;
        }
        $read=$this->legacySource->analyze($item->product_id,$g->location_id,$from,$until);
        if ($read->summary['observation_status']!=='complete') {
            throw new GenerationFailure('incomplete_publication','El origen heredado no acredita todo el intervalo.',true);
        }
        foreach ($read->days as $day) {
            if ($day['base_unit'] !== null && $day['base_unit'] !== $base['unit']) {
                throw new GenerationFailure('unit_incompatible','La unidad del histórico heredado difiere de la ficha actual.');
            }
        }
        return $this->own->transaction(function () use ($read,$checkpoint,$data): array {
            $metric=$this->legacyCache->persist($read);
            $data['legacy_metric_id']=$metric->id;
            $data['legacy_action']='refreshed';
            $data['legacy_fingerprint']=PlanningHistory::legacyFingerprint($read);
            $data['materialized_at']=$read->calculatedAt->format(DATE_ATOM);
            $checkpoint($data);
            return $data;
        });
    }

    /** Prepara y vincula la estadística antes del cálculo. Conserva un punto de recuperación incluso tras una interrupción.
     * @param ProposalGeneration $generation Ámbito congelado. @param ProposalGenerationItem $item Producto reservado.
     * @param DateTimeImmutable $until Fecha operativa en la zona nominal. @param string $mode Modalidad de este intento.
     * @param callable $checkpoint Persiste la evidencia bajo comprobación de propiedad. @return array Evidencia de procedencia.
     * @throws GenerationFailure Si falta el maestro o el perfil cambia durante la lectura. */
    public function prepare(ProposalGeneration $generation, ProposalGenerationItem $item, DateTimeImmutable $until,
        string $mode, callable $checkpoint): array
    {
        $from = $until->modify('-'.$generation->snapshot['policy']['analysis_days'].' days');
        $zone = $until->getTimezone()->getName();
        $base = ['mode'=>$mode,'requested_start'=>$from->format('Y-m-d'),'until'=>$until->format('Y-m-d'),'timezone'=>$zone,
            'planning_version'=>PolicyDrivenPlanningService::VERSION];
        $product = $this->adapters->resolve('products')->find($item->product_id);
        if ($product === null || $product->id !== $item->product_id) {
            throw new GenerationFailure('product_missing','El maestro actual no acredita la identidad del producto.');
        }
        if (trim($product->stockUnit) === '') {
            throw new GenerationFailure('unit_incompatible','La unidad de venta actual es desconocida.');
        }
        if (!$this->profiles->enabled()) {
            return $this->legacy($generation,$item,$from,$until,$base + ['unit'=>$product->stockUnit],$mode,$checkpoint);
        }
        $profile = $this->profiles->describe($product->id,$generation->location_id,$zone,$product->stockUnit,$product->statisticsCutoffDate);
        $effective = $product->statisticsCutoffDate === null ? $from : max($from,(new DateTimeImmutable($product->statisticsCutoffDate,$until->getTimezone()))->modify('+1 day'));
        $base += ['profile_hash'=>CommercialProfile::hash($profile),'unit'=>$product->stockUnit,
            'effective_start'=>min($effective,$until)->format('Y-m-d'),'statistics_version'=>CommercialProfile::VERSION];
        $old = $item->preparation;
        $previous = $old['sales_run_id'] ?? null;
        if ($effective < $until && ($mode !== 'force_refresh' || $previous !== null)) {
            $run = $this->store->compatibleRun($profile,$effective,$until,$product,runId:$previous);
            if ($run !== null) {
                $data = $base + ['origin'=>$previous ? $old['origin'] : 'reused','sales_run_id'=>(int)$run->id,
                    'materialized_at'=>(new DateTimeImmutable($run->getRawOriginal('calculated_at'),new \DateTimeZone('UTC')))->format(DATE_ATOM)];
                $checkpoint($data);
                return $data;
            }
        }
        if ($mode === 'cache_only' || $effective >= $until) {
            $data = $base + ['origin'=>$effective >= $until ? 'outside_cutoff' : 'missing','sales_run_id'=>null,'materialized_at'=>null];
            $checkpoint($data);
            return $data;
        }
        $data = [];
        $this->store->materialize($product->id,$generation->location_id,$from,$until,$zone,
            function (int $id, CommercialSalesData $publication) use ($checkpoint,$base,&$data): void {
                if (CommercialProfile::hash($publication->profile) !== $base['profile_hash']) {
                    throw new GenerationFailure('profile_incompatible','El perfil cambió durante la preparación estadística.',true);
                }
                $data = $base + ['origin'=>'refreshed','sales_run_id'=>$id,'materialized_at'=>$publication->calculatedAt->format(DATE_ATOM)];
                $checkpoint($data);
            });
        return $data;
    }
}
