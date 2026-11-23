<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;

/** Conflicto operativo comunicable al comprador sin detalles internos del driver. */
final class GenerationConflict extends \LogicException
{
    /** @param string $reason Código estable de recuperación. @param string $message Explicación comercial. */
    public function __construct(public readonly string $reason,string $message)
    {
        parent::__construct($message);
    }
}
