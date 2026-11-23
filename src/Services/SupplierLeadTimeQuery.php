<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\SupplierLeadTimeOptions;
use Asisnet\Replenishment\DTO\SupplierLeadTimeResolutionData;
use Asisnet\Replenishment\Models\ProductSupplier;
use Asisnet\Replenishment\Models\Policy;
use Illuminate\Contracts\Config\Repository;
use DateTimeImmutable;
use InvalidArgumentException;

/** Consulta historia independientemente de que las tablas propias estén migradas. */
final class SupplierLeadTimeQuery
{
    /**
     * Recibe fuentes registradas y servicios propios.
     * @param AdapterRegistry $adapters Registro histórico.
     * @param SupplierLeadTimeAnalyzer $analyzer Estadística.
     * @param SupplierLeadTimeResolver $resolver Precedencia.
     * @param PersistenceConnection $connection Lectura de configuración propia.
     * @param Repository $config Zona y criterios explícitos.
     */
    public function __construct(private AdapterRegistry $adapters,private SupplierLeadTimeAnalyzer $analyzer,private SupplierLeadTimeResolver $resolver,
        private PersistenceConnection $connection,private Repository $config) {}
    /**
     * Consulta sin escrituras, no crea relaciones comerciales ni certificados de cobertura.
     * @param string $productId Producto.
     * @param string $supplierId Proveedor.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio inclusivo de emisión.
     * @param DateTimeImmutable $until Corte exclusivo.
     * @param array<\Asisnet\Replenishment\DTO\LeadTimeCoverageData> $coverage Evidencia externa ya comprobada; el adaptador Orbis no la genera.
     * @param Policy|null $selectedPolicy Política seleccionada para esta consulta; evita predeterminados globales.
     * @return SupplierLeadTimeResolutionData Resolución con configuración inaccesible señalada, cuando corresponda.
     * @throws InvalidArgumentException Si los criterios o parámetros comerciales explícitos son inválidos.
     */
    public function analyze(string $productId,string $supplierId,string $locationId,DateTimeImmutable $from,DateTimeImmutable $until,array $coverage=[],?Policy $selectedPolicy=null): SupplierLeadTimeResolutionData
    {
        $c=$this->config->get('replenishment.supplier_lead_time',[]);
        $options=new SupplierLeadTimeOptions((int)($c['minimum_orders']??3),(string)($c['minimum_coverage']??'0.50'),(int)($c['maximum_age_days']??365),
            (int)($c['maximum_orders']??250),(string)($c['historical_measure']??'complete_receipt'));
        $history=$this->analyzer->analyze($this->adapters->resolve('purchase_history'),$productId,$supplierId,$locationId,$from,$until,$this->config->get('app.timezone','UTC'),$options,$coverage);
        $relation=null;$policy=null;$warnings=[];
        try{
            $schema=$this->connection->connection()->getSchemaBuilder();
            if($schema->hasTable('replenishment_product_suppliers')){$relation=ProductSupplier::query()->where('product_id',$productId)->where('supplier_id',$supplierId)->first();}
            else{$warnings[]='Las tablas propias no están disponibles; no se pudieron consultar plazos manuales almacenados. No se migró ninguna base.';}
            if($selectedPolicy===null&&($c['policy_id']??null)!==null){
                if($schema->hasTable('replenishment_policies')){$policy=Policy::find($c['policy_id']);if($policy===null){$warnings[]='La política elegida explícitamente no existe.';}}
                else{$warnings[]='La política almacenada no está disponible; no se ejecutaron migraciones.';}
            }
        }catch(\Throwable $error){$relation=null;$policy=null;$warnings[]='La conexión o configuración propia no está disponible; el diagnóstico histórico se conserva sin inferir plazos manuales.';}
        $default=$selectedPolicy===null?$this->config->get('replenishment.policy_defaults.default_lead_time_days'):null;
        if($default!==null){$validated=filter_var($default,FILTER_VALIDATE_INT);if($validated===false||$validated<0){throw new InvalidArgumentException('default_lead_time_days debe ser un entero no negativo explícito o NULL.');}$default=$validated;}
        return $this->resolver->resolve($history,$options,$relation,$selectedPolicy??$policy,$selectedPolicy===null?$default:null,$warnings);
    }
}
