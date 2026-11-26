<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Contracts;

/** Aplica la decisión de un solo producto en el maestro externo. */
interface InventoryParameterWriter
{
    /** Compara la fotografía completa y confirma sólo las diferencias aprobadas.
     * @param string $product Clave del maestro. @param string $unit Unidad observada.
     * @param array $expected Seis valores originales exactos, admitiendo NULL.
     * @param array $changes Únicamente los campos aprobados que difieren.
     * @return bool Verdadero tras commit, falso ante conflicto sin cambios.
     * @throws \Asisnet\Replenishment\Services\ParameterWriteRejected Si se acredita que nada se confirmó.
     * @throws \Throwable Si el resultado exige conciliación. */
    public function apply(string $product,string $unit,array $expected,array $changes): bool;
}
