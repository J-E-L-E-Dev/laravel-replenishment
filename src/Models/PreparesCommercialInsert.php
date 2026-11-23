<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;

/** Prepara detalles comerciales nuevos con los mismos casts y validaciones que una escritura individual. */
trait PreparesCommercialInsert
{
    /** Entrega los atributos físicos de una fila nueva, incluidos JSON y decimales exactos.
     * @param array $values Atributos de la publicación que se construye.
     * @return array Valores listos para INSERT en la conexión propia.
     * @throws \InvalidArgumentException Si un campo incumple las reglas del modelo.
     */
    public static function validatedInsert(array $values): array
    {
        $model = new static($values);
        $model->prepareForPersistence();
        $model->validatePersistence();
        return $model->getAttributes();
    }
}
