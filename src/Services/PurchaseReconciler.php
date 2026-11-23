<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Asisnet\Replenishment\DTO\PurchaseOrderData;
use Asisnet\Replenishment\DTO\PurchaseReceiptData;
use Asisnet\Replenishment\DTO\PurchaseReceiptLinkData;
use Asisnet\Replenishment\DTO\PurchaseReconciliationData;
use Asisnet\Replenishment\Casts\ExactDecimal;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Concilia evidencia documental sin inferir reglas de reapertura ni obligaciones pendientes. */
final class PurchaseReconciler
{
    /**
     * Contrasta relaciones y suma únicamente movimientos firmados verificables.
     * @param PurchaseOrderData $order Orden original.
     * @param string $productId Producto a conciliar, sin asignación arbitraria entre líneas repetidas.
     * @param array<PurchaseReceiptData> $receipts Candidatos acotados del adaptador, incluidos rechazos auditables.
     * @param DateTimeImmutable $until Límite exclusivo de observación.
     * @return PurchaseReconciliationData Cantidades observadas; pendiente confirmado desconocido hasta verificar reglas comerciales y completitud.
     */
    public function reconcile(PurchaseOrderData $order, string $productId, array $receipts, DateTimeImmutable $until): PurchaseReconciliationData
    {
        $lines = array_values(array_filter($order->lines, fn ($line) => $line->productId === $productId));
        $issues = $order->issues;
        $ordered = BigDecimal::zero();
        $unit = $lines[0]->baseUnit ?? null;
        $orderValid = count($lines) > 0 && $unit !== null && $order->orderedAt !== null && $order->supplierId !== '';
        $lineIds = [];
        foreach ($lines as $line) {
            $issues = array_merge($issues, $line->issues);
            if (isset($lineIds[$line->id()])) {
                $orderValid = false;
                $issues[] = 'duplicate_order_line';
            }
            $lineIds[$line->id()] = true;
            if ($line->quantity === null || $line->baseUnit !== $unit || $line->supplierId !== $order->supplierId
                || $line->document !== $order->document || $line->number !== $order->number) {
                $orderValid = false;
            } elseif (BigDecimal::of($line->quantity)->isNegative()) {
                $orderValid = false;
            } else {
                $ordered = $ordered->plus($line->quantity);
            }
        }
        if (!$orderValid) {
            $issues[] = 'order_quantity_or_unit_unverified';
        }
        if (count($lines) > 1) {
            $issues[] = 'line_allocation_unknown';
        }
        usort($receipts, fn ($a, $b) => ($a->movement->occurredAt <=> $b->movement->occurredAt) ?: strcmp($a->movement->id, $b->movement->id));
        $totals = ['receipt' => BigDecimal::zero(), 'return' => BigDecimal::zero(), 'return_reversal' => BigDecimal::zero()];
        $seen = [];
        $links = [];
        $valid = 0;
        $rejected = false;
        $hasReturn = false;
        $first = null;
        $completion = null;
        foreach ($receipts as $receipt) {
            $movement = $receipt->movement;
            if ($movement->productId !== $productId) {
                if (!in_array($movement->productId, array_column($order->lines, 'productId'), true)) {
                    $issues[] = 'unexpected_product';
                    $rejected = true;
                    $links[] = new PurchaseReceiptLinkData($receipt, false, 'unresolved', null, ['product_mismatch']);
                }
                continue;
            }
            $reasons = $receipt->issues;
            if (isset($seen[$movement->id])) {
                $issues[] = 'duplicate_movement';
                $rejected = true;
                continue;
            }
            $seen[$movement->id] = true;
            if ($receipt->orderDocument !== $order->document || $receipt->orderNumber !== $order->number) {
                $reasons[] = 'order_reference_mismatch';
            }
            if ($receipt->supplierId === null || $receipt->supplierId !== $order->supplierId) {
                $reasons[] = 'supplier_mismatch';
            }
            if ($movement->locationId !== $order->locationId) {
                $reasons[] = 'location_mismatch';
            }
            if ($order->orderedAt === null || $movement->occurredAt < $order->orderedAt || $movement->occurredAt >= $until
                || in_array('header_date_mismatch', $movement->issues, true)) {
                $reasons[] = 'incongruent_dates';
            }
            if (!$orderValid || $movement->baseUnit !== $unit || $movement->inventoryDelta === null) {
                $reasons[] = 'receipt_quantity_or_unit_unverified';
            }
            if (!isset($totals[$receipt->kind])) {
                $reasons[] = 'unsupported_receipt_type';
            }
            $verified = $reasons === [];
            $links[] = new PurchaseReceiptLinkData($receipt, $verified, $verified ? (count($lines) === 1 ? 'line' : 'order_product') : 'unresolved', $verified && count($lines) === 1 ? $lines[0]->id() : null, $verified && count($lines) > 1 ? ['line_allocation_unknown'] : array_values(array_unique($reasons)));
            if (!$verified) {
                $issues = array_merge($issues, $reasons);
                $rejected = true;
                continue;
            }
            $valid++;
            $totals[$receipt->kind] = $totals[$receipt->kind]->plus($movement->inventoryDelta);
            if ($receipt->kind === 'receipt') {
                $first ??= $movement->occurredAt;
                if ($completion === null && $totals['receipt']->isGreaterThanOrEqualTo($ordered)) {
                    $completion = $movement->occurredAt;
                }
            } else {
                $hasReturn = true;
            }
        }
        $net = $totals['receipt']->plus($totals['return'])->plus($totals['return_reversal']);
        $quality = !$orderValid || $valid === 0 ? 'insufficient' : 'partial';
        if ($orderValid && $valid > 0 && $net->isEqualTo($ordered)) {
            $quality = 'verified';
        }
        if ($rejected || $order->issues !== []) {
            $quality = 'ambiguous';
        }
        if ($valid > 0 && ($net->isNegative() || ($orderValid && $net->isGreaterThan($ordered)))) {
            $issues[] = 'incompatible_quantities';
            $quality = 'ambiguous';
        }
        if ($quality === 'partial') {
            $issues[] = 'partial_receipt';
        }
        if ($hasReturn) {
            $issues[] = 'return_reopening_unverified';
        }
        if ($valid === 0) {
            $issues[] = 'no_verified_receipt';
        }
        // El origen no demuestra completitud ni la semántica comercial de apertura/cierre.
        $issues[] = 'historical_completeness_unverified';
        $issues[] = 'commercial_status_unverified';
        return new PurchaseReconciliationData(
            $order, $productId, $quality, $orderValid ? ExactDecimal::normalize((string) $ordered) : null,
            $valid > 0 ? ExactDecimal::normalize((string) $totals['receipt']) : null,
            $valid > 0 ? ExactDecimal::normalize((string) $totals['return']) : null,
            $valid > 0 ? ExactDecimal::normalize((string) $totals['return_reversal']) : null,
            $valid > 0 ? ExactDecimal::normalize((string) $net) : null,
            $orderValid && $valid > 0 && !$rejected ? ExactDecimal::normalize((string) $ordered->minus($net)) : null,
            null, 'unverified', $first,
            $quality === 'verified' && !$hasReturn ? $completion : null,
            $links, array_values(array_unique($issues)), $until,
        );
    }
}


