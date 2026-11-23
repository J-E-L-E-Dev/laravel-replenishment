<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Contracts;
use Asisnet\Replenishment\DTO\PurchaseOrderData;
use Asisnet\Replenishment\DTO\PurchaseReceiptData;
use Asisnet\Replenishment\DTO\PurchaseReconciliationData;
use DateTimeImmutable;

/** Consulta evidencia de abastecimiento sin confundirla con pendientes confirmados. */
interface PurchaseHistorySource
{
    /**
     * Consulta una orden exacta.
     * @param string $document Código.
     * @param string $number Número.
     * @param string $locationId Destino lógico.
     * @return PurchaseOrderData|null Orden o ausencia comprobada.
     * @throws \RuntimeException Si el origen es inconsistente o falla.
     */
    public function find(string $document, string $number, string $locationId): ?PurchaseOrderData;

    /**
     * Concilia un producto de una orden hasta un límite exclusivo.
     * @param string $document Código.
     * @param string $number Número.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $until Corte operativo.
     * @return PurchaseReconciliationData Evidencia y limitaciones.
     * @throws \RuntimeException Si no existe la orden o falla la lectura.
     */
    public function reconcile(string $document, string $number, string $productId, string $locationId, DateTimeImmutable $until): PurchaseReconciliationData;

    /**
     * Recorre órdenes emitidas en [inicio, fin), conciliando movimientos anteriores a fin.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin.
     * @return iterable<PurchaseReconciliationData> Resultados por orden/producto con memoria acotada.
     * @throws \RuntimeException Si falla el origen.
     * @throws \InvalidArgumentException Si el intervalo es inválido.
     */
    public function between(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until): iterable;

    /**
     * Entrega recepciones incluso sin referencia vinculable.
     * @param string $productId Producto.
     * @param string $locationId Destino.
     * @param DateTimeImmutable $from Inicio.
     * @param DateTimeImmutable $until Fin.
     * @return iterable<PurchaseReceiptData> Entradas, devoluciones y reversiones originales sin inventar vínculos.
     * @throws \RuntimeException Si falla el histórico.
     */
    public function receiptsBetween(string $productId, string $locationId, DateTimeImmutable $from, DateTimeImmutable $until): iterable;
}


