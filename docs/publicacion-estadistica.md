# Publicación comercial y recuperación de una generación

## Protocolo de medición

Ejecutar `php vendor/bin/phpunit tests/CommercialStatisticsTest.php --filter testPublicationVolumes` antes y después sobre SQLite temporal con el mismo PHP y Xdebug desactivado. La muestra alterna días vacíos, días con una unidad y días con dos unidades. Se excluyen las migraciones y la lectura posterior de equivalencia. Desde la entrada a materialize se mide tiempo total con reloj monotónico; desde el evento de creación de la cabecera hasta el retorno se mide publicación, incluido el commit. Se cuentan sentencias de la conexión propia sin almacenar sus parámetros. El pico incremental de memoria PHP se mide sobre cada ejecución cuando el runtime permite reiniciar el contador. La comparación del calendario restaurado incluye sus cantidades exactas.

## Publicación e integridad

`CommercialSalesStore::materialize()` lee el intervalo semiabierto completo antes de abrir la transacción de `PersistenceConnection`. Crea una cabecera Eloquent con producto, destino, perfil, huellas, intervalo y fecha de cálculo. Los detalles pasan por los mismos casts, preparación y validación de los modelos que en una creación individual. Cada actualización forzada añade una publicación con identidad propia.

`REPLENISHMENT_STATISTICS_BATCH_SIZE` configura el máximo de filas por INSERT (100 por defecto). Cada sentencia lleva cinco columnas por día u ocho por unidad. El límite conservador de 999 parámetros admite como máximo 199 días o 124 unidades por lote. El escritor descarga el lote antes de añadir una fila que haga superar 256 KiB de valores. Una fila indivisible puede ocupar más. Sólo se acumula un lote preparado a la vez, además de la serie leída y el mapa de días.

Dentro de la misma transacción se realizan dos consultas de comprobación. La primera lee los días de esa cabecera ordenados por fecha. Verifica el calendario completo, estado, conteo y motivos, y recupera la clave primaria real de cada día, incluso con huecos en la numeración. La segunda recorre las unidades pertenecientes a esos días. Comprueba fecha, unidad histórica exacta, SHA-256, conteos y los cuatro decimales frente al agregado original. Las claves únicas impiden duplicar fechas o pares día/unidad. Se conservan el corte, los NULL, los ceros observados, las ventas, devoluciones, reversiones y los netos negativos.

El callback de generación fija la publicación al producto antes del commit, comprobando la versión y el propietario de la reserva. Un fallo revierte cabecera, días, unidades y vínculo. Los lectores mantienen la última publicación compatible confirmada. La carga por lotes se limita a detalles nuevos de esa cabecera.

## Medidas en SQLite temporal

PHP 8.2.28 en Windows. Una ejecución por versión, ventanas en orden ascendente y seguimiento detallado desactivado. La primera ventana incluye inicialización. Las flechas muestran el paso de escrituras individuales a lotes de 100. Los conteos incluyen la cabecera y las dos lecturas de comprobación de la versión nueva. Las duraciones son milisegundos de reloj transcurrido.

| Días | Sentencias SQL | INSERT | Publicación (ms) | Total (ms) | Pico incremental (MiB) | SHA-256 de la serie, prefijo común |
|---:|---:|---:|---:|---:|---:|---|
| 30 | 61 → 5 | 61 → 3 | 227,58 → 84,36 | 2591,85 → 186,45 | 5,60 → 5,54 | bfe242a97c7bc3fa |
| 90 | 181 → 5 | 181 → 3 | 125,67 → 197,01 | 133,01 → 204,36 | 0,16 → 0,34 | 8df897e619d3ff99 |
| 365 | 730 → 11 | 730 → 9 | 563,35 → 248,63 | 607,09 → 266,59 | 0,60 → 0,76 | 2cf6a5d46d29cf54 |
| 730 | 1460 → 19 | 1460 → 17 | 904,78 → 334,85 | 941,07 → 365,70 | 1,19 → 1,30 | 74b5ab898dada7a1 |

Las cuatro series coincidieron con la referencia y con su restauración. La reducción de sentencias se verifica automáticamente. La muestra de 90 días tardó más con lotes. Los tiempos variaron al ejecutar otras pruebas y la compilación al mismo tiempo. Estas muestras no estiman percentiles de producción.

## Diagnóstico de lectura en OrbisConnect

Lectura real de sólo consulta de `003-146`, del 23/11/2025 inclusive al 23/11/2026 exclusivo, sobre el mismo perfil. Ambas lecturas devolvieron 504 movimientos comerciales y una ventana completa de 365 días. En la evidencia de origen figuran 484 FAC, 19 NCC y 1 NDC. Se mantuvieron la identificación por documento y número, el conteo de encabezados y las exclusiones.

| Operación o tabla | Antes (ms) | Después (ms) |
|---|---:|---:|
| Lectura comercial completa, tiempo transcurrido | 68 026,19 | 8 236,03 |
| I00018, cada una de las tres consultas | 31 029,82 / 33 996,98 / 154,60 | 2 301,19 / 2 685,82 / 82,34 |
| I00017, consultas de detalle y alcance | 33,33 / 25,36 / 47,47 / 11,37 / 976,84 | 146,22 / 2 066,88 / 11,46 / 14,19 / 1,85 |
| I00001, lecturas de ficha | 869,08 / 3,44 | 604,15 / 3,40 |
| I00055, definiciones documentales | 298,67 / 2,59 | 201,13 / 2,19 |

En el adaptador del consumidor los encabezados se buscan en cada bloque agrupando los números exactos por su documento. Esto aprovecha el par documental y evita una rama OR por cada número. Un par que reaparece en otro bloque puede volver a consultarse. Se conservan la detección de encabezados ambiguos y los componentes documentales originales. Se aprovecharon los índices existentes sin cambios en SQL Server.

En una segunda comprobación de sólo lectura se evaluó el cálculo posterior con la publicación ya fijada. Duró **17 650,30 ms**. Entre las lecturas SQL Server destacaron I00001 (23 consultas, 8 863,08 ms), I00097 (1, 1 329,49 ms), C02092 (7, 283,49 ms), C02091 (18, 1 045,34 ms) e I00018 (6, 266,05 ms). En la conexión propia se observaron, entre otras, lecturas de relaciones (4, 145,68 ms), esquema (2, 460,25 ms), cabecera (1, 57,05 ms), días (1, 70,45 ms), unidades históricas (1, 123,37 ms), presentaciones (2, 60,78 ms) y unidades de compra (2, 1,20 ms). La simulación diagnóstica no ejecutó el comando de generación ni publicó su resultado.

Una sola consulta antigua de encabezados llegó a exceder 30 segundos en la medición. La traza histórica del timeout sólo identifica la llamada de Laravel al driver. Sigue sin conocerse qué sentencia o combinación exacta de etapas agotó aquella solicitud. El comprador confirmó después 11 de 11 calculados con cero errores técnicos en la generación 10. Las dos mediciones diagnósticas fueron independientes. Sigue pendiente medir solicitudes HTTP completas y la publicación real en un destino MySQL/MariaDB aislado, bajo carga representativa.

## Telemetría optativa

Habilitar temporalmente `REPLENISHMENT_PERFORMANCE_LOG=true` en la configuración efectiva del consumidor. El canal Laravel registra `replenishment.performance` con el producto, generación, intento y publicación cuando están resueltos. Se distinguen las etapas `commercial_read`, `daily_aggregation`, `insert_header`, `insert_days`, `day_ids`, `insert_units`, `validate_units`, `on_published`, `commit`, `planning` y `generation_product`. Las etapas de escritura indican filas y lotes. Cada etapa completada informa de duración y resultado. El total por producto abarca también adquisición y confirmación de la reserva.

La duración de las consultas completadas se agrega por driver, tabla y huella de la sentencia, con cantidad, suma y máximo. El registro omite texto SQL, parámetros y credenciales. Las etapas están anidadas. Para interpretar una solicitud, leer el tiempo total una sola vez. `daily_aggregation` mide tramos de preparación del calendario y agregación PHP fuera de las esperas del generador ERP. El residuo entre duración total y tiempos SQL incluye aplicación, conexión, transporte y esperas. Un cierre abrupto puede impedir la emisión del registro final de una etapa. Desactivar la opción después de la muestra.

## Recuperación y límites

El comando reserva un producto en UTC durante diez minutos y renueva la reserva al fijar la preparación. Otro comando debe respetar la reserva activa. Tras vencer, la recuperación guarda la etapa interrumpida y el vencimiento, asigna un nuevo propietario y reutiliza la publicación ya fijada cuando el perfil sigue siendo compatible. Cada confirmación comprueba token y versión. La repetición del mismo comando confirmado conserva su resultado. Tras tres intentos sin completar, la siguiente recuperación clasifica la línea para intervención y permite procesar las posteriores. La consulta de progreso no escribe datos.

Las pruebas utilizan SQLite temporal y solicitudes intercaladas de forma determinista. Cubren visibilidad entre conexiones, identificadores con huecos, lotes parciales, precisión, inmutabilidad y rollback al insertar, verificar o vincular la publicación. La comprobación de bloqueos y latencias con varios procesos PHP y un servidor MySQL/MariaDB sigue pendiente.

Si nuevas muestras superan el límite HTTP, se puede separar la preparación de la confirmación del cálculo en solicitudes reanudables. La primera debe publicar y fijar el identificador en una transacción. La segunda debe leer ese identificador y comprobar de nuevo versión y propietario. Si una sola lectura ERP excede el límite, primero haría falta un área privada con cursor e identidad documental idempotente. Sólo una validación completa del intervalo permitiría publicar esa estadística. Esta alternativa requiere un cambio posterior autorizado.

## Verificación de cierre

| Ámbito | Verificación |
|---|---|
| Paquete, PHP 8.2.28 | `CommercialStatisticsTest`, 13 pruebas y 205 aserciones satisfactorias. Incluye equivalencia estricta de la serie restaurada, telemetría, rollback y dos conexiones al mismo archivo SQLite temporal. |
| Consumidor, PHP 8.2.28 | `GenerationWorkD2Test`, `Adjustment732Test`, `PurchaseEligibilityTest`, `OrbisHistoryTest` y `OrbisPurchaseHistoryTest`, 70 pruebas y 680 aserciones satisfactorias. Incluye el regreso tardío del trabajador original después de que otro recuperó la reserva y el caso de siete procesados más cuatro pendientes. |
| Interfaz | 14 pruebas focalizadas aprobadas en `replenishment7323.test.mjs`, incluidas la caducidad con la página abierta y la confirmación explícita de recuperación. |
| Construcción | Vite terminó en un directorio temporal y se verificó la existencia del recurso de la pantalla a partir del manifiesto. Aviso de antigüedad de caniuse-lite. |
| Compatibilidad de sintaxis | 17 archivos PHP modificados comprobados con PHP 8.1.33. La ejecución funcional se hizo en PHP 8.2.28. |
