<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\DTO\InventoryMovementData;
use Asisnet\Replenishment\Services\HistoricalStockInspector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Verifica las condiciones genéricas de reconstrucción sin conocer esquemas ERP. */
final class HistoricalStockInspectorTest extends TestCase
{
    /** Construye una observación sintética. @param string $id Identidad. @param string $date Fecha. @param string $stock Existencia resultante. @param string $product Producto. @return InventoryMovementData Observación exacta. */
    private function movement(string $id, string $date, string $stock = '9.00', string $product = 'P'): InventoryMovementData
    {
        return new InventoryMovementData($id, $product, 'L', 'D', '1', 'sale', new DateTimeImmutable($date), '1.00', '1.00', '-1.00', '1.00', 'UND', 'UND', $stock, 'instant', [], [], $id);
    }

    /** El flujo vacío no fabrica observaciones y una pareja compatible no certifica continuidad. @return void */
    public function testConsistentPairAndImmutableIssues(): void
    {
        $inspector = new HistoricalStockInspector();
        self::assertSame([], iterator_to_array($inspector->inspect([])));
        $first = $this->movement('1', '2026-01-01T10:00:00Z');
        $second = $this->movement('2', '2026-01-02T10:00:00Z', '8.00');
        $rows = iterator_to_array($inspector->inspect([$first, $second]));
        self::assertSame([], $first->issues);
        self::assertSame('incomplete', $rows[0]->reconstructionQuality());
        self::assertSame('consistent', $rows[1]->reconstructionQuality());
        self::assertSame(['x'], $first->withIssues(['x', 'x'])->issues);
    }

    /** Una identidad consecutiva duplicada no debe contarse dos veces. @return void */
    public function testDuplicateIdentityIsRejected(): void
    {
        $row = $this->movement('1', '2026-01-01');
        $this->expectException(RuntimeException::class);
        iterator_to_array((new HistoricalStockInspector())->inspect([$row, $row]));
    }

    /** Rechaza mezclar productos en una reconstrucción individual. @return void */
    public function testMixedScopeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        iterator_to_array((new HistoricalStockInspector())->inspect([$this->movement('1', '2026-01-01'), $this->movement('2', '2026-01-02', '8', 'OTHER')]));
    }

    /** Rechaza fechas decrecientes aunque los identificadores aumenten. @return void */
    public function testUnorderedDatesAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        iterator_to_array((new HistoricalStockInspector())->inspect([$this->movement('1', '2026-01-02'), $this->movement('2', '2026-01-01')]));
    }
}
