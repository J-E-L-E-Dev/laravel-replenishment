<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\DTO\InventoryMovementData;
use Asisnet\Replenishment\DTO\PurchaseOrderData;
use Asisnet\Replenishment\DTO\PurchaseOrderLineData;
use Asisnet\Replenishment\DTO\PurchaseReceiptData;
use Asisnet\Replenishment\Services\PurchaseReconciler;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Protege la conciliación genérica sin infraestructura ni nombres de tablas del consumidor. */
final class PurchaseReconcilerTest extends TestCase
{
    /**
     * Crea una orden sintética.
     * @param array|null $lines Líneas alternativas.
     * @return PurchaseOrderData Orden de dos unidades.
     */
    private function order(?array $lines = null): PurchaseOrderData
    {
        return new PurchaseOrderData('D', '0001', 'S', 'L', new DateTimeImmutable('2026-01-01'), null, 'unknown', $lines ?? [new PurchaseOrderLineData('D', '0001', '1', 'P', 'S', '2.000000', 'U', 'U', null)]);
    }

    /**
     * Crea una recepción firmada ficticia.
     * @param string $id Identidad.
     * @param string $document Referencia candidata.
     * @return PurchaseReceiptData Recepción de dos unidades.
     */
    private function receipt(string $id = '1', string $document = 'D'): PurchaseReceiptData
    {
        $movement = new InventoryMovementData($id, 'P', 'L', 'R', '001', 'source_entry', new DateTimeImmutable('2026-01-03'), '2.000000', '2.000000', '2.000000', '0.000000', 'U', 'U', '8.000000');
        return new PurchaseReceiptData($movement, 'receipt', 'S', 'referencia original', $document, '0001');
    }

    /**
     * Una recepción duplicada no aumenta el neto y hace explícita la ambigüedad.
     * @return void
     */
    public function testDuplicateMovementsAreNotAddedTwice(): void
    {
        $row = $this->receipt();
        $result = (new PurchaseReconciler())->reconcile($this->order(), 'P', [$row, $row], new DateTimeImmutable('2026-02-01'));
        self::assertSame('2.000000', $result->netReceivedQuantity);
        self::assertCount(1, $result->links);
        self::assertSame('ambiguous', $result->quality);
        self::assertNull($result->calculatedPendingQuantity);
        self::assertContains('duplicate_movement', $result->issues);
    }

    /**
     * Ninguna coincidencia de proveedor o producto sustituye la referencia documental.
     * @return void
     */
    public function testWrongOrderReferenceCannotBeLinked(): void
    {
        $result = (new PurchaseReconciler())->reconcile($this->order(), 'P', [$this->receipt('1', 'OTHER')], new DateTimeImmutable('2026-02-01'));
        self::assertFalse($result->links[0]->verified);
        self::assertContains('order_reference_mismatch', $result->links[0]->issues);
        self::assertNull($result->netReceivedQuantity);
    }

    /**
     * Una identidad repetida de orden no duplica cantidades pedidas.
     * @return void
     */
    public function testDuplicateOrderLinesInvalidateOrderedQuantity(): void
    {
        $line = $this->order()->lines[0];
        $result = (new PurchaseReconciler())->reconcile($this->order([$line, $line]), 'P', [$this->receipt()], new DateTimeImmutable('2026-02-01'));
        self::assertNull($result->orderedQuantity);
        self::assertContains('duplicate_order_line', $result->issues);
        self::assertNull($result->confirmedPendingQuantity);
    }

    /**
     * La cobertura comprobable no demuestra el cierre comercial ni completa el histórico.
     * @return void
     */
    public function testObservedCoverageIsSeparateFromCommercialCertainty(): void
    {
        $result = (new PurchaseReconciler())->reconcile($this->order(), 'P', [$this->receipt()], new DateTimeImmutable('2026-02-01'));
        self::assertSame('verified', $result->quality);
        self::assertSame('0.000000', $result->calculatedPendingQuantity);
        self::assertNull($result->confirmedPendingQuantity);
        self::assertSame('unverified', $result->pendingQuality);
        self::assertContains('historical_completeness_unverified', $result->issues);
        self::assertSame('2026-01-03', $result->observedCompletionAt->format('Y-m-d'));
    }
}


