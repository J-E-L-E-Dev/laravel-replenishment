# Compras, recepciones y conciliación — Fase 3.2B

## Contratos y datos

`PurchaseHistorySource` separa la evidencia histórica de `PurchaseSource::pendingFor()`. Sus operaciones son:

- `find(document, number, locationId)`: orden completa con sus líneas, o NULL si se comprobó su ausencia.
- `reconcile(document, number, productId, locationId, until)`: conciliación por orden/producto, con corte exclusivo de movimientos.
- `between(productId, locationId, from, until)`: órdenes emitidas en [inicio, fin); sus recepciones se observan hasta fin, no sólo durante las fechas de emisión.
- `receiptsBetween(...)`: movimientos de abastecimiento del intervalo, incluyendo referencias ausentes o inválidas.

Se registra mediante `adapters.purchase_history`. `SupplierSource` y `SupplierData` siguen representando identidades de proveedores. Los proveedores asociados a productos se observan en las líneas de órdenes; eso no demuestra una oferta vigente ni un proveedor preferido.

| DTO | Responsabilidad |
| --- | --- |
| `PurchaseOrderData` | Código y número separados, proveedor, destino, emisión, estimación, estado comercial original y líneas |
| `PurchaseOrderLineData` | Identidad compuesta por documento, número y línea; producto, proveedor, cantidad, unidad y evidencia original |
| `PurchaseReceiptData` | `InventoryMovementData` reutilizado más referencia candidata y proveedor del movimiento |
| `PurchaseReceiptLinkData` | Vínculo verificado o rechazado, causas y granularidad |
| `PurchaseReconciliationData` | Sumas observadas, diferencia calculada, pendiente confirmado, calidad, vínculos y fechas |

Los identificadores permanecen opacos. `PurchaseOrderLineData::id()` serializa la tupla inequívoca; no concatena componentes de longitud desconocida. Todas las cantidades utilizables son cadenas decimales exactas. Los datos desconocidos permanecen NULL. Las unidades históricas no son presentaciones comerciales; no se deducen empaques ni factores.

## Conciliación genérica

`PurchaseReconciler` recibe DTO y nunca consulta el ERP ni persistencia. Comprueba identidad documental, producto, ubicación, proveedor, fechas, unidad comparable y cantidades normalizadas. Suma el efecto físico ya firmado de los tipos semánticos receipt, return y return_reversal una sola vez. El adaptador traduce los códigos propios del ERP a estos tipos; el conciliador no interpreta códigos originales. La interpretación específica del origen pertenece al adaptador; no se repite la lógica de normalización histórica.

Si existe una sola línea del producto, el vínculo puede identificarla. Con varias líneas del mismo producto, mantiene `order_product`, deja `lineId` en NULL y registra `line_allocation_unknown`. La recepción se suma una sola vez. Identidades repetidas de movimientos o líneas hacen explícita la ambigüedad. Un producto recibido ajeno a todas las líneas se registra como discrepancia.

La calidad describe **la evidencia observada**, no la completitud del ERP ni la obligación comercial:

| `quality` | Significado |
| --- | --- |
| `verified` | Vínculos comprobables y neto observado igual a la cantidad ordenada comparable |
| `partial` | Existen recepciones comprobables, pero el neto observado es menor a lo ordenado |
| `ambiguous` | Hay candidatos contradictorios, duplicados, fechas/proveedores/unidades incompatibles o exceso recibido |
| `insufficient` | No existe recepción comprobable o no se conoce la cantidad/unidad ordenada |

Una relación comprobada no demuestra que todas las recepciones estén referenciadas. Por ello se conserva `historical_completeness_unverified`. El campo `kind` de PurchaseReceiptData es semántico; el código original permanece en su movimiento. Los totales `receivedQuantity`, `returnedQuantity`, `restoredQuantity` y `netReceivedQuantity` corresponden exclusivamente a movimientos comprobados dentro de la evidencia consultada. Cero en un tipo significa ausencia dentro de ese conjunto observado; no certifica que no existan movimientos sin referencia. Si no hay ningún movimiento comprobado, los totales quedan en NULL. Cuando se rechazan candidatos, el neto conocido no debe usarse como total definitivo y no se publica una diferencia calculada.

`calculatedPendingQuantity` es la diferencia matemática entre ordenado y neto observado. Puede ser negativa si hubo exceso; no se recorta ni se presenta como obligación pendiente. `confirmedPendingQuantity` permanece NULL y `pendingQuality=unverified`: todavía faltan garantías de completitud, semántica de cierre/cancelación y reglas de reapertura tras devoluciones. Una NDP no demuestra que el proveedor deba reponerla; NCP tampoco certifica el cierre.

`PurchaseSource::pendingFor()` conserva su firma y `PurchaseData` permanece compatible. El contrato exige una excepción explícita si no puede verificarse el pendiente **completo del producto**. Un iterable vacío sólo es correcto para ausencia confirmada; nunca equivale a incertidumbre. El consumidor debe completar el iterable antes de publicar acumulados y descartarlos ante un error. El adaptador OrbisNET actual falla explícitamente y dirige a `purchase_history`, incluso cuando no encuentra órdenes: no dispone de reglas para certificar un pendiente global.

## Fechas para indicadores posteriores

Se conservan emisión, estimación de encabezado, estimaciones de líneas, primera entrada comprobable y fecha de cobertura observada. `observedCompletionAt` se publica sólo si el neto observado coincide con lo ordenado, no hay contradicciones ni devoluciones/reversiones. No representa un cierre comercial certificado ni garantiza que no existan movimientos omitidos.

Estas fechas permiten posteriormente comparar días calendario en la zona operativa; no son días hábiles. Las estimaciones no se sustituyen por recepciones reales. No se calculan promedios, métricas definitivas de proveedores ni recomendaciones.

## Uso

```php
$registry = app(\Asisnet\Replenishment\Services\AdapterRegistry::class);
$source = $registry->resolve('purchase_history');
$result = $source->reconcile($document, $number, $productId, $locationId, $until);
// Consultar quality, pendingQuality, issues y links antes de interpretar cantidades.
```

El adaptador fija los límites de bloque y documento, valida la zona operativa y señala explícitamente cualquier lectura incompleta. Los DTO de una conciliación son acotados a una orden, no a diez años de historial. Ninguna operación crea tablas, transacciones comerciales ni tareas automáticas.

## Verificación

Pruebas focalizadas del paquete: `PurchaseReconcilerTest`, `FoundationTest`, `DataTest` y `HistoricalStockInspectorTest`: **20 pruebas, 50 aserciones**, PHP 8.2.28 y PHPUnit 11.5.57. Incluyen duplicación, referencias incorrectas y separación de cobertura observada y certeza comercial.

En el consumidor, compras: **17 pruebas, 90 aserciones**; regresión histórica afectada por el lector compartido: **13 pruebas, 63 aserciones**, PHP 8.2.28 y PHPUnit 10.5.29. Se usaron SQLite aisladas y fixtures ficticios. No se repitió la matriz Laravel ni la batería completa de disponibilidad. No se amplían compatibilidades de Composer.

La evidencia específica de SQL Server y sus limitaciones se conserva en `docs/replenishment-compras.md` del consumidor. No se certificó una conciliación completa real en los casos inspeccionados; los casos completos y parciales están cubiertos sintéticamente. La siguiente fase requiere autorización.

