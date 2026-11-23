<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use RuntimeException;

/** Detecta identidades repetidas mediante ordenación externa, sin retener todos los eventos en memoria. */
final class EventIdentityIndex
{
    /** @var array<string> Huellas del bloque actual; nunca contiene cantidades comerciales. */
    private array $buffer = [];
    /** @var array<int,resource> Bloques ordenados de tamaño creciente. */
    private array $runs = [];

    /**
     * Añade una identidad a un bloque de hasta 4096 huellas.
     * @param string $id Identidad opaca del evento dentro del alcance analizado.
     * @return void
     * @throws RuntimeException Si se encuentra una repetición o falla el archivo temporal.
     */
    public function add(string $id): void
    {
        $this->buffer[] = hash('sha256', $id);
        if (count($this->buffer) >= 4096) {
            $this->flush();
        }
    }

    /**
     * Verifica también duplicados entre bloques antes de permitir publicar resultados.
     * @return void
     * @throws RuntimeException Si una identidad se repite o falla el almacenamiento temporal.
     */
    public function finish(): void
    {
        $this->flush();
        $merged = null;
        try {
            foreach ($this->runs as $level => $run) {
                unset($this->runs[$level]);
                if ($merged === null) {
                    $merged = $run;
                } else {
                    $merged = $this->merge($merged, $run);
                }
            }
        } finally {
            if (is_resource($merged)) {
                fclose($merged);
            }
        }
    }

    /**
     * Libera siempre los archivos temporales, también tras una fuente fallida.
     * @return void
     */
    public function close(): void
    {
        foreach ($this->runs as $run) {
            if (is_resource($run)) {
                fclose($run);
            }
        }
        $this->runs = [];
        $this->buffer = [];
    }

    /**
     * Ordena un bloque y lo combina como un contador binario de archivos.
     * @return void
     * @throws RuntimeException Si hay duplicados o no puede escribirse el bloque.
     */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        sort($this->buffer, SORT_STRING);
        if (count(array_unique($this->buffer)) !== count($this->buffer)) {
            throw new RuntimeException('duplicate_identity');
        }
        $run = $this->temporary();
        try {
            $bytes = implode("\n", $this->buffer)."\n";
            if (fwrite($run, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('identity_storage_failure');
            }
            $this->buffer = [];
            rewind($run);
            $level = 0;
            while (isset($this->runs[$level])) {
                $other = $this->runs[$level];
                unset($this->runs[$level]);
                $run = $this->merge($run, $other);
                $level++;
            }
            $this->runs[$level] = $run;
        } catch (\Throwable $error) {
            if (is_resource($run)) {
                fclose($run);
            }
            throw $error;
        }
    }

    /**
     * Combina dos archivos ordenados con memoria constante y cierra los originales.
     * @param resource $left Primer bloque.
     * @param resource $right Segundo bloque.
     * @return resource Bloque combinado y rebobinado.
     * @throws RuntimeException Si las huellas coinciden o falla una escritura.
     */
    private function merge($left, $right)
    {
        $out = null;
        try {
            $out = $this->temporary();
            rewind($left); rewind($right);
            $a = $this->readLine($left); $b = $this->readLine($right);
            while ($a !== false || $b !== false) {
                if ($a !== false && $a === $b) {
                    throw new RuntimeException('duplicate_identity');
                }
                if ($b === false || ($a !== false && strcmp($a, $b) < 0)) {
                    $value = $a; $a = $this->readLine($left);
                } else {
                    $value = $b; $b = $this->readLine($right);
                }
                if (fwrite($out, $value) !== strlen($value)) {
                    throw new RuntimeException('identity_storage_failure');
                }
            }
            rewind($out);
            return $out;
        } catch (\Throwable $error) {
            if (is_resource($out)) {
                fclose($out);
            }
            throw $error;
        } finally {
            fclose($left); fclose($right);
        }
    }

    /**
     * Distingue fin de archivo de un error de lectura, sin omitir identidades silenciosamente.
     * @param resource $file Archivo ordenado.
     * @return string|false Huella o fin confirmado.
     * @throws RuntimeException Si la lectura falla antes del fin del archivo.
     */
    private function readLine($file)
    {
        $line = fgets($file);
        if ($line === false && !feof($file)) {
            throw new RuntimeException('identity_storage_failure');
        }
        return $line;
    }

    /**
     * Crea un archivo eliminado al cerrarse.
     * @return resource Archivo temporal.
     * @throws RuntimeException Si no puede crearse.
     */
    private function temporary()
    {
        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('identity_storage_failure');
        }
        return $file;
    }
}


