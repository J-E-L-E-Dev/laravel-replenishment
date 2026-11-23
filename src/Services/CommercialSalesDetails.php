<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;

use Asisnet\Replenishment\DTO\CommercialSalesData;
use Asisnet\Replenishment\Models\{CommercialSalesDay,CommercialSalesUnit};
use RuntimeException;

/** Inserta y coteja los detalles de una única cabecera dentro de la transacción de su publicación. */
final class CommercialSalesDetails
{
    /** @param PersistenceConnection $own Conexión propia. @param PerformanceTrace $trace Diagnóstico opcional. */
    public function __construct(private PersistenceConnection $own, private PerformanceTrace $trace) {}

    /** Publica días y unidades verificando los vínculos reales antes de permitir el callback.
     * @param int $id Cabecera recién insertada. @param CommercialSalesData $data Intervalo completo.
     * @return void @throws RuntimeException Si faltan días, unidades, conteos o identificadores consistentes. */
    public function insert(int $id, CommercialSalesData $data): void
    {
        $days = $this->own->connection()->table((new CommercialSalesDay())->getTable());
        $units = $this->own->connection()->table((new CommercialSalesUnit())->getTable());
        $this->write($days, $this->days($id,$data), 5, 'insert_days');
        $map = $this->trace->measure('day_ids', ['records'=>count($data->days)], function () use ($days,$id,$data) {
            $map = [];
            $date = $data->from;
            foreach ((clone $days)->where('run_id',$id)->orderBy('sales_date')->cursor() as $row) {
                $expected = $data->days[$row->sales_date] ?? null;
                if (!$expected || (int)$row->run_id !== $id || $row->sales_date !== $date->format('Y-m-d')
                    || $row->state !== $expected['state'] || ($row->event_count === null ? null : (int)$row->event_count) !== $expected['event_count']
                    || json_decode($row->issues ?? 'null',true,512,JSON_THROW_ON_ERROR) !== $expected['issues']) {
                    throw new RuntimeException('La cobertura diaria insertada difiere de la lectura comercial.');
                }
                $map[$row->sales_date] = (int)$row->id;
                $date = $date->modify('+1 day');
            }
            if ($date != $data->until || count($map) !== count($data->days)) {
                throw new RuntimeException('El mapa de fechas no cubre exactamente la ventana comercial.');
            }
            return $map;
        });
        $expected = $this->write($units, $this->units($data,$map), 8, 'insert_units');
        $this->trace->measure('validate_units',['records'=>$expected],function () use ($units,$days,$id,$data,$map,$expected) {
            $count = 0;
            $dates = array_flip($map);
            $stored = (clone $units)->whereIn('day_id',(clone $days)->where('run_id',$id)->select('id'));
            foreach ($stored->cursor() as $row) {
                $bucket = $data->days[$dates[$row->day_id]]['units'][$row->unit] ?? null;
                if (!$bucket || $row->unit_hash !== hash('sha256',$row->unit) || (int)$row->event_count !== $bucket['event_count']) {
                    throw new RuntimeException('La unidad insertada no concuerda con su día o identidad comercial.');
                }
                foreach (ObservedDemandCalculator::QUANTITIES as $field) {
                    if ((string)$row->$field !== $bucket[$field]) {
                        throw new RuntimeException('La cantidad insertada difiere del agregado exacto.');
                    }
                }
                $count++;
            }
            // La clave única (day_id, unit_hash) impide compensar una unidad ausente con otra repetida.
            if ($count !== $expected) { throw new RuntimeException('Faltan unidades históricas en la publicación.'); }
        });
    }

    /** Prepara cobertura y coteja estado y movimientos antes de insertar.
     * @param int $id Cabecera. @param CommercialSalesData $data Observaciones.
     * @return iterable Filas físicas validadas. @throws RuntimeException Ante cobertura incoherente. */
    private function days(int $id, CommercialSalesData $data): iterable
    {
        $effective = $data->effective->format('Y-m-d');
        foreach ($data->days as $date=>$day) {
            $outside = $date < $effective;
            $state = $outside ? 'outside_cutoff' : ($day['units'] ? 'complete' : 'zero_observed');
            $count = $outside ? null : array_sum(array_column($day['units'],'event_count'));
            if ($state !== $day['state'] || $count !== $day['event_count'] || ($outside && $day['units'])) {
                throw new RuntimeException('El estado comercial no concuerda con los movimientos observados.');
            }
            yield CommercialSalesDay::validatedInsert(['run_id'=>$id,'sales_date'=>$date,'state'=>$state,
                'event_count'=>$count,'issues'=>$day['issues']]);
        }
    }

    /** Asocia cada agregado a su clave real y conserva las cuatro cantidades exactas.
     * @param CommercialSalesData $data Lectura original. @param array $map Claves de días verificadas.
     * @return iterable Filas para inserción. @throws RuntimeException Si la clave de unidad es inconsistente. */
    private function units(CommercialSalesData $data,array $map): iterable
    {
        foreach ($data->days as $date=>$day) {
            foreach ($day['units'] as $key=>$bucket) {
                if ((string)$key !== $bucket['unit'] || $bucket['event_count'] < 1) {
                    throw new RuntimeException('Identidad o conteo de unidad comercial inválidos.');
                }
                yield CommercialSalesUnit::validatedInsert(['day_id'=>$map[$date],'unit_hash'=>hash('sha256',$bucket['unit'])] + $bucket);
            }
        }
    }

    /** Agrupa filas sin exceder 999 parámetros ni acumular más de 256 KiB de valores por sentencia ordinaria.
     * @param \Illuminate\Database\Query\Builder $table Tabla propia. @param iterable $rows Filas físicas.
     * @param int $columns Columnas por fila. @param string $stage Etiqueta diagnóstica.
     * @return int Cantidad total insertada. @throws \Throwable Si falla la preparación o inserción. */
    private function write($table, iterable $rows,int $columns,string $stage): int
    {
        $limit = min(max(1,(int)config('replenishment.statistics_batch_size',100)),intdiv(999,$columns));
        return $this->trace->measure($stage, ['batch_limit'=>$limit], function () use ($rows,$table,$limit) {
            $batch = [];
            $bytes = $count = $batches = 0;
            foreach ($rows as $row) {
                $size = array_sum(array_map(static fn ($value)=>strlen((string)$value),$row));
                if ($batch && (count($batch) >= $limit || $bytes + $size > 262144)) {
                    $table->insert($batch);
                    $batch = [];
                    $bytes = 0;
                    $batches++;
                }
                $batch[] = $row;
                $bytes += $size;
                $count++;
            }
            if ($batch) { $table->insert($batch); $batches++; }
            $this->trace->counts($count,$batches);
            return $count;
        });
    }
}
