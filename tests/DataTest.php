<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\DTO\InventoryData;
use Asisnet\Replenishment\DTO\ProductData;
use Asisnet\Replenishment\DTO\PurchaseData;
use Asisnet\Replenishment\DTO\SaleData;
use DateTimeImmutable;
use Error;
use PHPUnit\Framework\TestCase;

/**
 * Protege la precisión decimal, las identidades opacas y la inmutabilidad del transporte.
 */
final class DataTest extends TestCase
{
    /**
     * Conserva cantidades exactas y signos sin usar aritmética de coma flotante.
     */
    public function testExactQuantitiesAndUnknownDatesArePreserved(): void
    {
        $instant = new DateTimeImmutable('2026-11-07T10:00:00-04:00');
        $inventory = new InventoryData('0001', '01', '-123456789012345.123456', $instant);
        $sale = new SaleData('s1', '0001', '01', '-0.125', $instant);
        $purchase = new PurchaseData('p1', '0001', 's01', '01', '0.125', null);

        self::assertSame('-123456789012345.123456', $inventory->onHand);
        self::assertSame('0001', $inventory->productId);
        self::assertSame($instant, $inventory->observedAt);
        self::assertSame('-0.125', $sale->quantity);
        self::assertNull($purchase->expectedAt);
    }

    /**
     * Evita modificaciones posteriores de un DTO compartido entre componentes.
     */
    public function testDataIsImmutable(): void
    {
        $product = new ProductData('0001', 'Producto', 'unidad');
        $this->expectException(Error::class);
        $product->id = '0002';
    }
}
