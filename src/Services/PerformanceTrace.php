<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

/** Cronometría optativa por operación. Agrupa consultas sin conservar SQL, parámetros ni datos de conexión. */
final class PerformanceTrace
{
    private array $frames = [];

    /** Mide una etapa y conserva el resultado o la excepción original.
     * @param string $stage Etapa comercial. @param array $context Identidades y contadores técnicos.
     * @param callable $work Operación a ejecutar. @return mixed Resultado original. @throws \Throwable Error original. */
    public function measure(string $stage, array $context, callable $work): mixed
    {
        if (!config('replenishment.performance_log')) { return $work(); }
        $parent = end($this->frames);
        $this->frames[] = ['context'=>$context + ($parent['context'] ?? []), 'sql'=>[]];
        $start = hrtime(true);
        $outcome = 'fallo';
        try {
            $result = $work();
            $outcome = $result instanceof \Asisnet\Replenishment\DTO\CommercialSalesData ? $result->status : 'completo';
            return $result;
        } finally {
            $frame = array_pop($this->frames);
            $this->record($stage, $start, $frame['context']['diagnosis'] ?? $outcome,
                $frame['context'] + ['sql'=>$frame['sql'], 'sql_ms'=>round(array_sum(array_column($frame['sql'], 'ms')), 3)]);
        }
    }

    /** Enriquece las etapas abiertas con el producto o la publicación resueltos posteriormente.
     * @param array $values Identidades y conteos de la operación. @return void */
    public function tag(array $values): void
    {
        foreach ($this->frames as &$frame) { $frame['context'] = $values + $frame['context']; }
    }

    /** Registra totales exclusivos de la etapa actual. @param int $records Filas. @param int $batches Lotes. @return void */
    public function counts(int $records,int $batches): void
    {
        $key = array_key_last($this->frames);
        if ($key !== null) { $this->frames[$key]['context'] += ['records'=>$records,'batches'=>$batches]; }
    }

    /** Acumula tiempos del driver por forma de consulta y tabla, sin registrar cada movimiento.
     * @param QueryExecuted $event Consulta completada. @return void */
    public function query(QueryExecuted $event): void
    {
        if (!$this->frames) { return; }
        preg_match('/\b(?:from|into|update)\s+[\x22\x60\[]?([a-zA-Z0-9_]+)/i', $event->sql, $match);
        $key = $event->connection->getDriverName().'/'.($match[1] ?? 'otra').'/'.substr(hash('sha256',$event->sql),0,8);
        foreach ($this->frames as &$frame) {
            $group = $frame['sql'][$key] ?? ['count'=>0,'ms'=>0,'max_ms'=>0];
            $group['count']++;
            $group['ms'] += $event->time;
            $group['max_ms'] = max($group['max_ms'],$event->time);
            $frame['sql'][$key] = $group;
        }
    }

    /** Registra la duración transcurrida sin interferir con el resultado de negocio ante fallos del log.
     * @param string $stage Etapa. @param int $start Marca monotónica. @param string $outcome Resultado.
     * @param array $context Identidades técnicas, sin credenciales ni excepciones internas. @return void */
    public function record(string $stage,int $start,string $outcome,array $context): void
    {
        $this->duration($stage, hrtime(true)-$start, $outcome, $context);
    }

    /** Publica una duración acumulada de tramos discontinuos de PHP o de una etapa continua.
     * @param string $stage Etapa. @param int $elapsed Nanosegundos. @param string $outcome Resultado.
     * @param array $context Identidades y conteos. @return void */
    public function duration(string $stage,int $elapsed,string $outcome,array $context): void
    {
        if (!config('replenishment.performance_log')) { return; }
        try {
            $parent = end($this->frames);
            Log::info('replenishment.performance', ['stage'=>$stage,'duration_ms'=>round($elapsed/1e6,3),
                'outcome'=>$outcome] + $context + ($parent['context'] ?? []));
        } catch (\Throwable $ignored) { /* La telemetría es opcional. */ }
    }
}
