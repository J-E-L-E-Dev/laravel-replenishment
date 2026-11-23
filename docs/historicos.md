# Ventas y movimientos históricos — Fase 3.2A

## Responsabilidades

`InventoryMovementSource::between` entrega observaciones por producto, ubicación e intervalo [inicio, fin), mediante un iterable. El adaptador debe garantizar identidad única y orden cronológico determinista, conservar cantidades desconocidas y declarar la precisión temporal. La clave `adapters.movements` permite resolverlo mediante `AdapterRegistry`; inicialmente queda sin configurar.

`InventoryMovementData` conserva identidad de línea, documento y número separados, transacción, fecha efectiva, cantidad original, cantidad en unidad base verificable, efecto físico, cantidad comercial firmada, unidad histórica, existencia resultante y evidencia de calidad. Las magnitudes son cadenas decimales exactas o NULL. `metadata` conserva evidencia del origen y puede contener información comercial: no registrarla indiscriminadamente en logs.

`SaleSource` y `SaleData` se reutilizan. El argumento opcional final `movement` enlaza la observación completa sin romper constructores existentes. Venta positiva, devolución negativa y reversión positiva se suman exactamente una vez. Una anulación contabilizada mediante un contramovimiento firmado debe conservarse; no equivale a descartar un borrador. Los movimientos físicos no comerciales no se proyectan como ventas.

El consumidor decide sus equivalencias verificadas, filtros y conexiones. El paquete no contiene consultas ni tablas del ERP. Ninguno de estos componentes escribe persistencia propia ni altera disponibilidad o reservas actuales.

## Calidad para futuras reconstrucciones

`HistoricalStockInspector::inspect` contrasta observaciones consecutivas con memoria constante. Exige un producto y ubicación, fechas no decrecientes e identidades consecutivas distintas. La unicidad global corresponde al adaptador y a la clave de origen; el inspector no conserva todos los identificadores en memoria.

- `opening_observation_missing`: falta una observación inicial comparable; el primer movimiento del intervalo siempre tiene esta limitación.
- `same_day_order_ambiguous`: dos observaciones de un mismo día no disponen de hora fiable.
- `source_order_conflict`: la secuencia numérica del origen retrocede al ordenar por fecha; evidencia posible de registro retroactivo, no prueba de una hora real.
- `unit_changed`: cambia el código histórico de unidad.
- `stock_delta_mismatch`: existencia previa más efecto actual difiere de la existencia resultante registrada.

`reconstructionQuality()` devuelve `incomplete` si faltan cantidades, unidad o anclaje; `ambiguous` si hay discordancias u orden incierto; `consistent` si la pareja observable es compatible. **Consistente no certifica continuidad, ausencia de omisiones ni días con stock.** Tampoco prueba que una unidad mantuviera históricamente su definición. Un intervalo sin movimientos devuelve un iterable vacío, sin inferir existencia cero.

El adaptador puede agregar causas específicas. Un movimiento no normalizable conserva evidencia y cantidades desconocidas. La proyección comercial debe fallar ante una venta no normalizable; el consumidor debe completar el iterable antes de publicar un total. Una excepción tardía invalida cualquier acumulación parcial.

## Uso

```php
$registry = app(\Asisnet\Replenishment\Services\AdapterRegistry::class);
$source = $registry->resolve('movements');
foreach ($source->between($productId, $locationId, $from, $until) as $movement) {
    // Procesar o exportar cada observación, conservando sus limitaciones de calidad.
}
```

La lectura por bloques pertenece al adaptador. No convertir el flujo completo a una colección para historiales extensos. No se garantiza una instantánea entre lecturas concurrentes ni entre contratos.

## Verificación focalizada

Pruebas nuevas: `HistoricalStockInspectorTest`; pruebas de compatibilidad afectadas: `DataTest` y `FoundationTest`. Ejecutadas con PHP 8.2.28 y las dependencias instaladas del paquete: 16 pruebas, 33 aserciones. La integración específica se prueba en el consumidor con fixtures sintéticos y dos conexiones SQLite independientes. No se repitió la matriz completa de Laravel ni las pruebas completas de disponibilidad.

La Fase 3.2B fue autorizada posteriormente; consultar [compras y conciliación](compras.md). No se implementan pronósticos, rotación, agotamientos definitivos, estacionalidad ni recomendaciones de compra.

## Evidencia de integración del consumidor

Los adaptadores del consumidor superaron 13 pruebas y 63 aserciones con SQLite aisladas. Se contrastaron 1884 movimientos de ocho productos reales en ventanas de 15 días; coincidieron conteos, identidades y netos firmados. Hubo una devolución parcial vinculada y un caso con ajustes. La inspección conservó discrepancias de existencias y precisión diaria; no certifica reconstrucción continua. El informe detallado permanece en `docs/replenishment-historicos.md` del consumidor, sin acoplar el paquete a su esquema ni incluir información comercial en fixtures.

