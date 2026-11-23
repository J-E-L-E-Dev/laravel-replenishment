<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/** Transporta exclusivamente diagnósticos públicos y acotados de una unidad de trabajo. */
final class GenerationFailure extends RuntimeException
{
    /** @param string $reason Código público. @param string $message Diagnóstico seguro. @param bool $retryable Admite nuevo intento. */
    public function __construct(public readonly string $reason, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    /** Clasifica el fallo sin exponer la consulta, credenciales ni traza. @param Throwable $error Fallo interno.
     * @return self Diagnóstico seguro. */
    public static function describe(Throwable $error): self
    {
        if ($error instanceof self) {
            return $error;
        }
        if (preg_match('/timeout|timed out|tiempo de espera|HYT0[01]/i',$error->getMessage())) {
            return new self('timeout','La fuente excedió el tiempo de espera.',true);
        }
        if (preg_match('/^08[A-Z0-9]{3}$/i',(string)$error->getCode()) || preg_match('/SQLSTATE\[08[A-Z0-9]{3}\]/i',$error->getMessage())) {
            return new self('connection_error','No se pudo mantener la conexión con el origen de datos.',true);
        }
        if ($error instanceof QueryException) {
            return new self('persistence_failure','Falló el acceso a la persistencia. Compruebe la conexión antes de reintentar.',true);
        }
        if ($error instanceof \InvalidArgumentException || $error instanceof \LogicException) {
            return new self('configuration_required','Revise la relación, la unidad y la configuración comercial.');
        }
        return new self('source_unavailable','La fuente no completó la lectura del producto.',true);
    }
}
