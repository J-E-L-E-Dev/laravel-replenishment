<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\DTO;

/** Conserva los parámetros observados, incluidos NULL y anomalías anteriores a la revisión. */
final class InventoryParameterData
{
    public const FIELDS = ['minimum','maximum','minimum_days','maximum_days','reorder_days','reorder_point'];

    /** @param string $product Código exacto. @param string|null $description Descripción observada.
     * @param string|null $unit Unidad de inventario. @param int|null $scale Decimales autorizados para la unidad.
     * @param array $values Seis valores decimales exactos o NULL, sin conversión comercial.
     * @throws \InvalidArgumentException Si el adaptador omite campos o pierde precisión. */
    public function __construct(public readonly string $product, public readonly ?string $description,
        public readonly ?string $unit, public readonly ?int $scale, public readonly array $values)
    {
        if ($scale !== null && !in_array($scale,[0,1,2],true)) {
            throw new \InvalidArgumentException('Precisión de unidad incompatible con los parámetros.');
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field,$values) || ($values[$field] !== null
                && (!is_string($values[$field]) || !preg_match('/^-?\d+(?:\.\d+)?$/D',$values[$field])))) {
                throw new \InvalidArgumentException('La lectura del parámetro no es un decimal exacto.');
            }
        }
    }
}
