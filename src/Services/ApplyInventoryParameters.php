<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\Contracts\InventoryParameterWriter;
use Asisnet\Replenishment\Models\{ParameterApplication,ParameterReview,ProposalGeneration,ProposalGenerationItem};
use LogicException;

/** Reserva una revisión antes de contactar el maestro y registra el resultado después de su confirmación. */
final class ApplyInventoryParameters
{
    /** @param PersistenceConnection $own Destino de auditoría. @param InventoryParameterCalculator $rules Validador de decisiones guardadas. */
    public function __construct(private PersistenceConnection $own,private InventoryParameterCalculator $rules) {}

    /** Despacha una aplicación individual. Una respuesta perdida nunca autoriza repetir el UPDATE.
     * @param ProposalGeneration $generation Ámbito resuelto por el consumidor. @param int $itemId Línea de origen.
     * @param int $reviewId Revisión mostrada. @param string $actor Usuario autenticado.
     * @param InventoryParameterWriter $writer Adaptador autorizado.
     * @param int|null $expectedAttemptId Último intento visto al confirmar un bloque (cero si es el primero).
     * @return ParameterApplication Resultado durable.
     * @throws LogicException Ante revisión obsoleta, reserva sin resolver o contexto transaccional incorrecto.
     * @throws \Throwable Si la auditoría no puede confirmarse. */
    public function apply(ProposalGeneration $generation,int $itemId,int $reviewId,string $actor,InventoryParameterWriter $writer,?int $expectedAttemptId=null): ParameterApplication
    {
        if ($this->own->connection()->transactionLevel()!==0) {
            throw new LogicException('La aplicación requiere confirmar su reserva antes de contactar el maestro.');
        }
        [$attempt,$review]=$this->own->transaction(function () use ($generation,$itemId,$reviewId,$actor,$expectedAttemptId) {
            $item=ProposalGenerationItem::where('generation_id',$generation->id)->lockForUpdate()->findOrFail($itemId);
            $review=ParameterReview::where('item_id',$itemId)->findOrFail($reviewId);
            $last=ParameterApplication::where('review_id',$reviewId)->orderByDesc('attempt')->lockForUpdate()->first();
            if ($last && in_array($last->status,['applied','conflict'],true)) { return [$last,null]; }

            $blocked=ParameterApplication::whereIn('review_id',ParameterReview::select('id')->where('item_id',$itemId))
                ->where(function ($query) { $query->where('status','pending')->orWhere('diagnosis','uncertain'); })->exists();
            if ($blocked) { throw new ParameterApplicationPending('Hay un intento en curso o sin resultado confirmado. Consulte la auditoría y concilie antes de reintentar.'); }
            $snapshot=$review->snapshot;
            $hash=hash('sha256',$item->getRawOriginal('revised_result') ?? $item->getRawOriginal('result') ?? '');
            if ($review->status!=='approved' || !($snapshot['compatible'] ?? false)
                || $item->product_id!==($snapshot['product'] ?? null) || $generation->location_id!==($snapshot['location'] ?? null)
                || $hash!==($snapshot['source_hash'] ?? null) || ParameterReview::where('item_id',$itemId)->where('revision','>',$review->revision)->exists()) {
                throw new LogicException('La revisión no es una aprobación vigente del producto. Recargue su estado.');
            }
            // Un bloque repetido sólo puede abrir un intento contra la versión confirmada por el operador.
            if ($expectedAttemptId!==null && $expectedAttemptId!==(int)($last?->id ?? 0)) {
                if ($last) { return [$last,null]; }
                throw new LogicException('El intento indicado no pertenece a esta revisión.');
            }
            $decision=$this->rules->decide($snapshot,$review->decisions);
            $changes=[];
            foreach ($decision['decisions'] as $field=>$choice) {
                if ($choice!==$review->decisions[$field]) { throw new LogicException('Los valores aprobados son inconsistentes. Revise la evidencia.'); }
                if ($choice['changed'] && $choice['mode']!=='keep') { $changes[$field]=$choice['value']; }
            }
            if (!$changes || $decision['status']!=='approved') { throw new LogicException('La revisión carece de cambios aprobados aplicables.'); }
            $attempt=ParameterApplication::create(['review_id'=>$reviewId,'attempt'=>($last?->attempt ?? 0)+1,'actor_id'=>$actor,
                'status'=>'pending','expected'=>$snapshot['current'],'changes'=>$changes]);
            if (!$attempt->exists) { throw new \RuntimeException('No se pudo registrar el intento previo a la aplicación.'); }
            return [$attempt,$review];
        });
        if ($review===null) { return $attempt; }

        try {
            $updated=$writer->apply($review->snapshot['product'],$review->snapshot['current_unit'],$attempt->expected,$attempt->changes);
            $status=$updated?'applied':'conflict';
            $diagnosis=$updated?null:'snapshot_changed';
        } catch (ParameterWriteRejected $error) {
            $status='error';
            $diagnosis='not_committed';
        } catch (\Throwable $error) {
            $status='error';
            $diagnosis='uncertain';
        }
        // Si falla esta escritura, la reserva ya confirmada permanece pendiente y bloquea otro envío.
        return $this->own->transaction(function () use ($attempt,$status,$diagnosis) {
            $attempt->status=$status;
            $attempt->diagnosis=$diagnosis;
            if (!$attempt->save()) { throw new \RuntimeException('No se pudo registrar el resultado de la aplicación.'); }
            return $attempt;
        });
    }
}
