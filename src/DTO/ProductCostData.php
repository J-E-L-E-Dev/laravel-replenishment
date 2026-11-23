<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\DTO;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/** Conserva costo exacto y evidencia; un número sin moneda o unidad no produce un total monetario. */
final class ProductCostData
{
    /**
     * Recibe la lectura sin inferir moneda, conversiones ni unidad desde el nombre de la columna.
     * @param string $productId Producto.
     * @param string|null $amount Costo exacto por costUnit o desconocido.
     * @param string|null $currency Moneda verificada o desconocida.
     * @param string|null $costUnit Unidad verificada o desconocida.
     * @param string $source Procedencia configurada.
     * @param DateTimeImmutable $queriedAt Momento de consulta.
     * @param string $quality verified o unknown.
     * @param array<string> $warnings Limitaciones.
     * @throws InvalidArgumentException Si hay decimales inválidos o se declara verificación sin semántica completa.
     */
    public function __construct(public readonly string $productId, public readonly ?string $amount, public readonly ?string $currency,
        public readonly ?string $costUnit, public readonly string $source, public readonly DateTimeImmutable $queriedAt,
        public readonly string $quality = 'unknown', public readonly array $warnings = [])
    {
        if ($productId === '' || !in_array($quality, ['verified', 'unknown'], true) || ($amount !== null && !preg_match('/^\d{1,18}(?:\.\d{1,12})?$/D', $amount)) || ($quality === 'verified' && ($amount === null || $currency === null || !preg_match('/^[A-Z]{3}$/D', $currency) || $costUnit === null || trim($costUnit) === ''))) {
            throw new InvalidArgumentException('Un costo verificado requiere decimal exacto, moneda y unidad comprobadas.');
        }
    }
    /** Calcula únicamente costos por unidad base coincidente. @param string $baseUnit Unidad del producto. @param string $factor Unidades base por paquete. @param string $packages Paquetes decididos. @return array Fotografía de costo, subtotal y causas; desconocidos conservados. @throws InvalidArgumentException Si factor o cantidad no son válidos. */
    public function totals(string $baseUnit, string $factor, string $packages): array
    {
        if (!BigDecimal::of($factor)->isPositive() || BigDecimal::of($packages)->isNegative()) { throw new InvalidArgumentException('Factor positivo y cantidad no negativa requeridos para valorar.'); }
        $usable = $this->quality === 'verified' && $this->costUnit === $baseUnit;
        $package = $usable ? BigDecimal::of($this->amount)->multipliedBy($factor) : null;
        return ['product_id' => $this->productId, 'source' => $this->source, 'queried_at' => $this->queriedAt->format(DATE_ATOM), 'quality' => $this->quality,
            'currency' => $this->currency, 'cost_unit' => $this->costUnit, 'unit_cost' => $usable ? $this->amount : null,
            'package_cost' => $package === null ? null : (string) $package, 'subtotal' => $package === null ? null : (string) $package->multipliedBy($packages),
            'warnings' => array_merge($this->warnings, $usable ? [] : ['El costo no tiene semántica verificada por unidad base; el subtotal permanece desconocido.'])];
    }
}
