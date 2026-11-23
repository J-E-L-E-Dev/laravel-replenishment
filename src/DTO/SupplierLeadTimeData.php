<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use DateTimeImmutable;

/** Diagnóstico histórico aislado por producto, proveedor y destino. */
final class SupplierLeadTimeData
{
    /**
     * Conserva ventana, evaluación y evidencia acotada.
     * @param string $productId Producto.
     * @param string $supplierId Proveedor.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio de emisión.
     * @param DateTimeImmutable $until Corte exclusivo de órdenes y recepciones.
     * @param DateTimeImmutable $evaluatedAt Fecha de evaluación.
     * @param string $timezone Zona civil.
     * @param array $summary Estadísticas y limitaciones.
     * @param array<SupplierLeadTimeObservationData> $observations Evidencia de órdenes, limitada por opciones.
     */
    public function __construct(public readonly string $productId, public readonly string $supplierId, public readonly string $locationId,
        public readonly DateTimeImmutable $from, public readonly DateTimeImmutable $until, public readonly DateTimeImmutable $evaluatedAt,
        public readonly string $timezone, public readonly array $summary, public readonly array $observations) {}
    /**
     * Devuelve diagnóstico compacto o auditoría completa para persistencia propia.
     * @param bool $detailed Incluye todas las observaciones del lote acotado.
     * @return array Datos serializables.
     */
    public function audit(bool $detailed=false): array
    {
        $result=['product_id'=>$this->productId,'supplier_id'=>$this->supplierId,'location_id'=>$this->locationId,'from'=>$this->from->format(DATE_ATOM),
            'until'=>$this->until->format(DATE_ATOM),'evaluated_at'=>$this->evaluatedAt->format(DATE_ATOM),'timezone'=>$this->timezone,
            'algorithm_version'=>\Asisnet\Replenishment\Services\SupplierLeadTimeAnalyzer::VERSION,'summary'=>$this->summary];
        if($detailed){$result['observations']=array_map(fn($o):array=>$o->audit(),$this->observations);}
        else{$result['excluded_samples']=array_slice(array_values(array_map(fn($o):array=>array_intersect_key($o->evidence,array_flip(['order_key','exclusions'])),array_filter($this->observations,fn($o):bool=>$o->evidence['exclusions']!==[]))),0,10);}
        return $result;
    }
}
