# Disponibilidad — Fase 3.1

## Alcance

AvailabilityQuery coordina ProductSource, InventorySource y ReservationSource mediante el registro de adaptadores existente. AvailabilityCalculator recibe únicamente DTO normalizados; no consulta tablas, conexiones ni modelos de persistencia. No hay pronósticos, históricos de ventas, recomendaciones de compra ni escrituras.

Los contratos conservan sus firmas. ReservationData añade kind al final del constructor, con valor predeterminado unclassified, y permite quantity NULL para preservar compromisos desconocidos. Las llamadas anteriores de cuatro argumentos siguen siendo válidas; sus cantidades se contabilizan por separado como unclassifiedReserved, sin atribuirles una naturaleza inventada.

## Categorías y fórmula

Las categorías son fixed (reserva fija), manual (reserva manual), quotation (presupuesto vigente) y unclassified (compatibilidad con adaptadores anteriores). Los adaptadores deben emitir identidades estables y distintas por compromiso dentro del producto y ubicación.

Para una integración con las tres categorías identificadas:

```text
totalCommitted = fixedReserved + manualReserved + quotationReserved
available = onHand - totalCommitted
positiveAvailable = max(0, available)
overcommitted = available < 0
```

Si un adaptador anterior entrega reservas sin categoría, unclassifiedReserved también se incluye en totalCommitted; nunca se omite un compromiso por carecer de clasificación. La integración inicial clasifica todos sus compromisos, por lo que ese componente es cero.

El saldo negativo se conserva en available. positiveAvailable es un dato adicional, no reemplaza el saldo contable. Las cantidades se transportan como cadenas en decimal(24,6). Brick Math realiza sumas y restas exactas, con comprobación de rango mediante el normalizador existente; no se usan float, double ni redondeos silenciosos. Se declara la dependencia directa de Brick Math, que ya estaba instalada mediante Laravel, sin actualizar su versión en el consumidor.

## Desconocidos y errores

Un producto inexistente produce NULL desde AvailabilityQuery. Una observación física desconocida permanece NULL. Un compromiso de cantidad desconocida se representa con ReservationData.quantity NULL, no mediante una colección vacía. Si una categoría contiene cualquier cantidad desconocida, su subtotal, totalCommitted y available son NULL; overcommitted y positiveAvailable también son NULL cuando el disponible no se conoce.

Una colección vacía confirma ausencia de reservas y equivale a cero. Los compromisos deben ser no negativos; la existencia física puede ser negativa. Un identificador repetido con igual categoría y cantidad normalizada se cuenta una sola vez. Identidades repetidas con datos contradictorios, categorías no reconocidas o mezclas de producto/ubicación producen InvalidArgumentException.

Los errores de lectura no se convierten en resultados vacíos. Los adaptadores conservan la responsabilidad de normalizar sus datos y explicar fallos del origen. No se deducen unidades, presentaciones comerciales ni conversiones.

## Uso desde un consumidor

```php
$result = app(Asisnet\Replenishment\Services\AvailabilityQuery::class)
    ->forProduct('0001', 'alcance-logico-configurado');
```

El resultado contiene onHand, fixedReserved, manualReserved, quotationReserved, unclassifiedReserved, totalCommitted, available, positiveAvailable, overcommitted, productId, locationId y observedAt. Todos los nombres técnicos se mantienen en inglés.

La ubicación debe proceder de una configuración explícita del consumidor; no se inventan almacenes ni se utiliza una clasificación departamental. Las consultas de contratos independientes no prometen una instantánea transaccional: observedAt identifica la lectura física, no certifica que el origen permaneciera inmóvil durante toda la consulta.

## Verificación

En la Fase 3.1 se ejecutaron 10 pruebas focalizadas del paquete (8 nuevas de disponibilidad y 2 de compatibilidad de DTO), con 33 aserciones satisfactorias en PHP 8.2.28, Laravel 12.71.0 y Brick Math 0.14.8. Cubren desglose, precisión, negativos, ausencia de reservas, desconocidos, identidades repetidas, incompatibilidad de alcance y constructores anteriores.

Los adaptadores y la evidencia de SQL Server se documentan en el consumidor. No se modifican las doce tablas, el resolver independiente de persistencia ni los comandos de migración. La Fase 3.2 no está iniciada.
