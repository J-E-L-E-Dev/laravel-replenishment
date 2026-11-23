# Demanda histórica observada — Fase 4.1

## Alcance y componentes

El motor calcula **ventas registradas observadas**, no demanda potencial. Un día sin ventas no demuestra que hubiera mercancía ni que existiera demanda cero. No estima agotamientos, disponibilidad histórica, demanda perdida, estacionalidad, pronósticos ni compras recomendadas.

- `SaleData` añade al final del constructor los argumentos opcionales `kind` y `baseUnit`. El constructor anterior sigue funcionando; la ausencia de clasificación o unidad verificable produce una observación incompleta, no una deducción por signo.
- `ObservedDemandCalculator` consume una sola vez `SaleSource`, genera días calendario y consolida ventanas, meses y años.
- `EventIdentityIndex` detecta identidades repetidas con bloques de 4096 huellas SHA-256 y ordenación externa. Sólo almacena huellas temporales, no movimientos; combina archivos con memoria acotada y los elimina al cerrar, también si falla la fuente. Una huella repetida invalida el intervalo conservadoramente.
- `ObservedDemandData` conserva serie diaria, límites, zona, versión, fecha de cálculo y resúmenes auditables.
- `ObservedDemandQuery` resuelve el contrato configurado y aplica el corte operativo predeterminado.
- `ObservedDemandStore` separa persistencia y consulta de caché del procesamiento del origen.
- `DailySale` valida la caché reconstruible; `ProductMetric` conserva una nueva instantánea inmutable en cada persistencia.

El paquete no contiene consultas, tablas ni códigos de transacción del ERP. Sólo interpreta `sale`, `return` y `return_reversal`. La traducción de códigos originales pertenece al adaptador.

## Fórmulas y semántica

Por día y período:

| Componente | Fórmula |
| --- | --- |
| Bruto | Suma de cantidades no negativas de `sale` |
| Devoluciones | Magnitud positiva de cantidades no positivas de `return` |
| Reversiones | Suma de cantidades no negativas de `return_reversal` |
| Neto | Bruto − devoluciones + reversiones; equivale a sumar cantidades originales ya firmadas |

El tipo no se deduce del signo. Una reversión positiva no se cuenta como venta bruta. Signos incompatibles, cantidades inválidas, unidades desconocidas o trazas contradictorias invalidan la observación diaria correspondiente. Un cambio entre unidades base verificadas dentro de una serie invalida la serie: no se suman unidades diferentes.

Las devoluciones afectan el día efectivo en que se registraron; no se trasladan a la factura original. Se admiten netos negativos. Los datos adjuntos del movimiento conservan trazabilidad, pero los movimientos individuales no se duplican en las tablas analíticas.

Los indicadores utilizan estas definiciones explícitas:

- Días con movimientos comerciales: días con al menos un evento, incluida una devolución o reversión.
- Días con ventas positivas: días con **bruto positivo**, aunque el neto del día sea cero o negativo.
- Días sin ventas registradas: días calendario menos días con bruto positivo; pueden contener devoluciones.
- Días sin movimientos comerciales: días calendario menos días con cualquier evento comercial.
- Promedio diario calendario: neto / días calendario del período completamente observado.
- Promedio neto por día con ventas: neto del período / días con bruto positivo; NULL si no existen esos días. Incluye las devoluciones del período en el numerador.
- Frecuencia de venta: días con bruto positivo / días calendario. Es una proporción observada, no una clasificación de rotación.
- Promedio mensual observado: media aritmética de los netos de **meses calendario completos** incluidos en el intervalo. Excluye meses parciales, informa el denominador y devuelve NULL si no hay meses completos o alguno de ellos es incompleto.
- Variación de cada ventana: neto actual − neto del período inmediatamente anterior de igual duración. El porcentaje es diferencia / neto anterior × 100, sólo si la base anterior es positiva y ambos períodos están completos; en otro caso permanece NULL.

Las sumas utilizan aritmética decimal exacta. Cocientes y porcentajes se redondean explícitamente a seis decimales con HALF_UP. No se usa float. Las cantidades persistidas son DECIMAL(24,6), o texto exacto en SQLite. Si un total excede la representación admitida, no se publica como cantidad definitiva.

## Calendario y cobertura

Todos los intervalos son semiabiertos: [inicio, corte). Se exigen medianoches de la zona operativa. `ObservedDemandQuery` usa `app.timezone`, excluye el día en curso y por defecto consulta los 365 días anteriores al inicio del día actual. El calculador permite límites históricos explícitos para pruebas y reutilización; la consulta y el comando impiden cortes futuros o que incluyan el día en curso.

Las ventanas de 30, 60, 90, 180 y 365 días terminan en el mismo corte. Se superponen como consultas, pero la comparación anterior usa segmentos adyacentes no superpuestos. No se suman ventanas entre sí. Para comparar una ventana de 365 días con la anterior hay que proporcionar al menos 730 días de cobertura; el motor no hace consultas adicionales ocultas.

Meses y años usan sus límites reales, incluidos bisiestos y cambios de año. Los extremos parciales se muestran con sus límites observados y `partial_calendar_period=true`. Una ventana fuera del intervalo consultado permanece incompleta; no se extrapola ni rellena con ceros. Cada análisis admite hasta diez años calendario.

## Calidad de ventas e inventario

Se separan `sales_quality_issues` e `inventory_quality_issues`. Precisión diaria, orden intradía incierto, falta de anclaje inicial y discrepancias entre movimiento y existencia resultante se conservan como limitaciones de reconstrucción; **no descartan automáticamente ventas normalizadas válidas**. Se mantiene la incertidumbre identificada en la Fase 3.2A y no se certifican días agotados.

Si el contrato emite un evento comercial sin clasificación/unidad verificable, ese día es incompleto y sus componentes quedan NULL. Los otros días sólo se consideran observados cuando la fuente termina correctamente. Si la iteración falla, la identidad se repite, se mezclan alcances o aparece un evento fuera del intervalo, se invalida todo el intervalo solicitado. Nunca se publican acumulaciones parciales como definitivas. Una fuente que falla antes de poder identificar el día concreto produce `source_failure` para todo el intervalo; no se exponen mensajes SQL o credenciales.

Una fuente completada sin eventos confirma cero ventas **registradas** en su intervalo según el contrato; no prueba completitud absoluta del ERP, disponibilidad ni demanda potencial. Los días que faltan en la caché son desconocidos. No se asignan valores numéricos arbitrarios a calidad o confianza.

## Persistencia y reconstrucción

La migración adicional `2026_11_08_000002_add_observed_sales`:

1. Crea `replenishment_daily_sales`, con unicidad por producto, ubicación y fecha e índice de fecha/estado.
2. Añade `reversed_returns` a `replenishment_product_metrics`, porque devoluciones y reversiones no son el mismo componente.
3. Añade `observed_sales_summary` como JSON almacenado en texto, para conservar ventanas, meses, años, indicadores, límites y calidad sin reinterpretar columnas antiguas.

Las doce tablas previas se conservan. La ampliación de métricas es nullable y compatible con filas históricas anteriores. Bruto, devoluciones y neto reutilizan sus columnas existentes. `demand_*`, `daily_demand`, demanda ponderada y demás indicadores aún no definidos permanecen NULL; las ventanas observadas se guardan con nombres explícitos en `observed_sales_summary`.

Días agotados, días de disponibilidad, demanda perdida, seguridad, punto de pedido y mínimos/máximos recomendados permanecen NULL. La auditoría incluye estos indicadores no estimados; las columnas existentes de métricas tampoco se rellenan arbitrariamente.

La caché almacena componentes, cantidad de eventos, unidad, estado, causas comerciales y de inventario, zona, versión y fecha de cálculo. No conserva movimientos individuales. Desde Fase 5.1 sólo una reconstrucción completa puede reemplazar observaciones, incluidos ceros verificados, sin modificar fechas ni alcances ajenos. Se rechazan los lotes incompletos para conservar la caché anterior. Los registros tardíos y correcciones requieren reconstruir los intervalos afectados explícitamente.

Cada lote se valida mediante `DailySale`. El upsert en bloques de 50 filas se limita a esta **caché analítica reconstruible**, después de las validaciones del modelo; las métricas de dominio se crean mediante Eloquent y siguen inmutables. Caché y nueva métrica se publican en una transacción de `PersistenceConnection`. Si falla cualquier bloque o la métrica final, no queda visible ninguna escritura parcial. No se modifica el destino predeterminado de Laravel.

`ObservedDemandStore::read` consolida caché sin consultar el ERP. Huecos, versión o zona incompatibles producen días incompletos. Conserva las fechas originales de cálculo y muestra sus extremos en el resumen; la fecha del resumen leído no significa que el ERP se haya consultado de nuevo. No se permite reconstruir persistencia desde un resultado de caché. No hay promesa de frescura automática ni instantánea transaccional del origen; las reconstrucciones concurrentes compatibles siguen la última escritura confirmada.

La migración se aplica exclusivamente con `replenishment:migrate`; su reversión respeta el último lote propio. Si una instalación nueva aplica ambas migraciones en un lote, el rollback de ese lote revierte ambas. Una instalación existente que incorpora sólo la nueva migración revierte únicamente la ampliación. No se ejecutaron migraciones sobre las bases reales de consult-web.

## Ejemplo de uso

```php
$result = app(\Asisnet\Replenishment\Services\ObservedDemandQuery::class)
    ->analyze($productId, $locationId, $from, $until);
// Revisar estado, cobertura y causas; esta llamada no escribe.
$metric = app(\Asisnet\Replenishment\Services\ObservedDemandStore::class)->persist($result);
// Persistencia explícita y atómica sólo en tablas propias, tras instalar la migración.
```

## Verificación y límites

Pruebas focalizadas: `ObservedDemandTest`, `ConnectionIsolationTest`, `DataTest`; consumidor: `OrbisHistoryTest`, ampliado con semántica y diagnóstico. Cubren componentes, signos, netos negativos, fechas locales y bisiestas, ventanas, huecos, unidades/tipos desconocidos, fallo tardío, duplicados entre bloques externos, métricas inmutables, reconstrucción parcial, rollback atómico, versión de caché, migración/reversión, aislamiento y ausencia de escrituras al ERP.

La documentación del consumidor conserva el contraste acotado de SQL Server. La validez de observaciones comerciales no certifica el inventario histórico. No se repitió la matriz completa de Laravel ni suites ajenas al cambio. La siguiente fase requiere autorización.

Resultado final: 30 pruebas y 170 aserciones en el paquete, más 16 pruebas y 97 aserciones en el consumidor: 46 pruebas y 267 aserciones satisfactorias. Sintaxis comprobada con PHP 8.1.33; ejecución focalizada con PHP 8.2.28. Las migraciones y escrituras se probaron exclusivamente en SQLite aisladas.


## Corrección de publicación en Fase 5.1

La publicación ahora rechaza transaccionalmente cualquier resultado incompleto y conserva la caché completa anterior. No inserta días NULL ni métricas parciales como reemplazo. La lectura diagnóstica sigue representando desconocidos mediante NULL. Véase [protección y auditoría](estacionalidad.md).
