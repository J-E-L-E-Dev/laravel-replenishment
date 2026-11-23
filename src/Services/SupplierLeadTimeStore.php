<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\SupplierLeadTimeData;
use Asisnet\Replenishment\Models\ProductSupplier;
use Asisnet\Replenishment\Models\SupplierMetric;
use InvalidArgumentException;
use RuntimeException;

/** Publica únicamente evidencia histórica de recepciones, nunca valores manuales disfrazados de estadística. */
final class SupplierLeadTimeStore
{
    /**
     * Recibe destino propio de esquema y transacción.
     * @param PersistenceConnection $connection Conexión configurada.
     */
    public function __construct(private PersistenceConnection $connection) {}
    /**
     * Añade una instantánea auditable por destino sin sobrescribir métricas previas.
     * @param SupplierLeadTimeData $history Historia finalizada con al menos una observación documental válida.
     * @param int|null $productSupplierId Relación existente elegida explícitamente.
     * @return SupplierMetric Nueva métrica con alcance y auditoría.
     * @throws InvalidArgumentException Si faltan evidencia o relación compatible.
     * @throws RuntimeException Si falta la ampliación de esquema propia.
     * @throws \Throwable Si falla la publicación; revierte la transacción.
     */
    public function persist(SupplierLeadTimeData $history,?int $productSupplierId): SupplierMetric
    {
        if($history->summary['reading_status']!=='complete'||($history->summary['observed_first_receipt']['observations']??0)<1||$productSupplierId===null){throw new InvalidArgumentException('Se requiere historia documental válida y una relación producto-proveedor propia existente; no se guardan plazos manuales como métricas.');}
        $schema=$this->connection->connection()->getSchemaBuilder();
        if(!$schema->hasTable('replenishment_supplier_metrics')||!$schema->hasColumn('replenishment_supplier_metrics','location_id')||!$schema->hasColumn('replenishment_supplier_metrics','lead_time_evidence')){
            throw new RuntimeException('Falta la ampliación propia de métricas de proveedores; revise replenishment:migrate. No se ejecutaron migraciones.');
        }
        return $this->connection->transaction(function()use($history,$productSupplierId):SupplierMetric{
            $relation=ProductSupplier::find($productSupplierId);
            if($relation===null||$relation->product_id!==$history->productId||$relation->supplier_id!==$history->supplierId){throw new InvalidArgumentException('La relación propia no corresponde al producto y proveedor analizados.');}
            $stats=$history->summary['observed_first_receipt'];
            return SupplierMetric::create(['product_supplier_id'=>$productSupplierId,'location_id'=>$history->locationId,'analysis_start'=>$history->from,'analysis_end'=>$history->until,
                'calculated_at'=>$history->evaluatedAt,'algorithm_version'=>SupplierLeadTimeAnalyzer::VERSION,'average_lead_time_days'=>$stats['average_days'],
                'minimum_lead_time_days'=>$stats['minimum_days'],'maximum_lead_time_days'=>$stats['maximum_days'],'lead_time_deviation'=>$stats['deviation_days'],
                'estimation_source'=>'observed_first_receipt','verified_orders'=>$history->summary['orders_with_verified_link'],'partial_receipts'=>$history->summary['partial_receipts_observed'],
                'quantity_fulfillment'=>null,'date_fulfillment'=>null,'confidence'=>null,'lead_time_evidence'=>$history->audit(true)]);
        });
    }
}
