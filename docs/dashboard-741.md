# Dashboard analítico y operativo — Fase 7.4.1

## Arquitectura y alcance

El paquete aporta `ReplenishmentDashboardQuery`, `DashboardSalesQuery`, `DashboardAnalysisQuery` y el DTO inmutable `DashboardScope`. Los servicios sólo leen modelos propios, resueltos por `PersistenceConnection`. El consumidor conserva HTTP, sesión, permisos, Inertia, búsqueda de maestros, navegación y gráficas. No se modificaron motores, contratos de cálculo, migraciones ni dependencias.

`DashboardAnalysisQuery` comparte la comprobación de versiones entre pronósticos y estacionalidad para no duplicar la vinculación de auditorías. No invoca `ForecastQuery`, `SeasonalityQuery` ni entrenamientos. `DashboardSalesQuery` utiliza exclusivamente `ObservedDemandStore::read` y el calendario de `SalesHistoryBreakdownService`, con lectura directa desautorizada. Agregar cantidades ya materializadas no reconstruye datos del ERP ni publica nuevas métricas.

## Indicadores y filtros

`DashboardScope` exige ubicación lógica y fechas civiles semiabiertas con hasta 366 días. El consumidor recibe fecha final inclusiva y la convierte a límite exclusivo. Proveedor y estado de propuesta son opcionales; se conserva la identidad textual. El estado de propuesta sólo afecta a cabeceras de propuestas. Las series comerciales tienen un intervalo mensual independiente.

- Propuestas: cabeceras por `draft`, `in_review`, `approved`, `rejected` y `cancelled`. Una aprobación es interna, no una orden emitida.
- Generaciones: estados reales `processing`, `ready`, `saving`, `saved` y `cancelled`. Cálculo finalizado significa `ready` o `saved`; reanudable significa `processing` o `saving`.
- Errores: generaciones distintas que contienen líneas `error`.
- Líneas procesadas: estados diferentes de `pending`. Productos únicos: `COUNT(DISTINCT product_id)` en esas líneas dentro del ámbito; no se suman como productos únicos las repeticiones entre ejecuciones.
- Decisiones y costos: exclusivamente una generación seleccionada, sin formar una propuesta actual ficticia a partir de varias ejecuciones. Se conservan excluidos, pendientes, estados originales y subtotales exactos separados por moneda. Sin costo o decisión, el total es parcial.

Las propuestas recientes cuentan productos activos distintos, no líneas duplicadas. El último editor se recupera del último evento `add`, `adjust`, `remove` o `notes`; si no existe, queda desconocido. Los actores se exponen como IDs almacenados. La política sólo se muestra cuando existe una única generación vinculada verificable; no se reconstruye a partir de configuración vigente ni se atribuye una política común a líneas heterogéneas.

## Ventas y calendario

Se ofrecen doce o veinticuatro meses completos, o un intervalo personalizado de hasta veinticuatro meses. El corte excluye el día operativo en curso. Se mantienen FAC, NCC y NDC en sus fechas: neto = FAC − NCC + NDC, mediante el agregador exacto existente. No se mezclan productos ni unidades.

Cada mes conserva observación completa/incompleta, parcialidad civil, días completos y desconocidos, unidad y procedencia. El cero requiere observación verificada; los huecos permanecen NULL. Las gráficas de barras no unen períodos desconocidos. Las cantidades se transportan como cadenas exactas; Chart.js sólo las representa, sin cálculos comerciales en JavaScript.

Año → mes → semanas reutiliza semanas lunes-domingo recortadas al mes. Sólo se solicitan semanas al expandir un mes. Los resúmenes anuales corresponden al intervalo seleccionado, no a un año completo inferido.

Cuando faltan días, se examinan como máximo 25 métricas recientes de ventas observadas que solapen el intervalo. Se acepta únicamente un mes completo con identidad, zona, límites y versión compatibles y origen `source`. Se identifica la métrica y su fecha. Si no se conservó la unidad, queda desconocida y ese mes no se dibuja como compatible con una unidad inferida del ERP. Un resumen mensual no inventa semanas ni completa un total anual desconocido. Los resúmenes anuales siguen procediendo de la caché diaria; no se mezclan automáticamente instantáneas históricas distintas para completarlos.

## Tendencia y versiones analíticas

La tendencia procede del último resultado persistido del producto dentro del ámbito operativo. Se muestran clasificación, ventanas reales, cantidades y cambio relativo almacenado como proporción. No se recalibran umbrales ni se calcula un porcentaje cuando falta evidencia. Las hipótesis originales quedan accesibles.

Las versiones se paginan por producto, ubicación, fecha y algoritmo. Se selecciona por defecto el ancla más reciente. La auditoría debe enlazar explícitamente `demand_forecast_ids` o `seasonal_pattern_ids`, con producto, ubicación, fecha y algoritmo coincidentes. Se comprueba la presencia completa de las filas vinculadas. Auditoría ambigua, incompleta o más de cien candidatas de la misma fecha produce desconocimiento, no calidad inventada. Sin vínculo inequívoco se presenta únicamente el registro seleccionado, sin ensamblar una generación supuesta. El listado agrupa fecha y versión; no identifica dos lotes independientes con la misma fecha y versión como dos opciones distintas.

Para estacionalidad se exigen doce meses distintos de la misma ejecución antes de mostrar un gráfico anual. `period_class` se conserva por fila; calidad global, años y unidad sólo provienen de auditoría. Índice superior a uno no demuestra temporada alta; recurrencia descriptiva no equivale a confianza estadística.

La calidad de cada pronóstico se recupera por período y método dentro de la auditoría vinculada. Sin ella se muestra «Calidad no disponible». No se infiere calidad por el método ni por la aprobación de una propuesta. Los intervalos NULL permanecen ausentes. Sólo se grafican pronósticos mensuales completos, sin meses duplicados y con unidad histórica conocida y compatible; el resto permanece consultable en tabla. No se interpolan cantidades a días.

## Rendimiento, seguridad y límites

Los indicadores se agregan en SQL; propuestas y generaciones usan paginación real de 25 cabeceras. Los conteos de líneas, eventos y vínculos se consultan por lotes, sin N+1. Las decisiones de una ejecución se leen por bloques de 200 y no cargan su JSON de cálculo. No hay consultas ERP en la carga inicial. La verificación de un producto es explícita e individual; la búsqueda por descripción reutiliza el navegador paginado del consumidor y exige proveedor.

Se revisaron los índices existentes de propuestas por proveedor/ubicación/fecha, estado/fecha, líneas por propuesta y la unicidad generación/producto. No se agregó una migración por conveniencia visual. Las cabeceras de generación carecen de índice compuesto de ámbito/fecha; sus agregados pueden recorrer cabeceras. El rendimiento real con grandes históricos debe medirse en una instancia aislada antes de proponer índices. La paginación no aporta una instantánea distribuida: cambios concurrentes pueden modificar conteos entre peticiones.

La sesión web y `replenishment.view` directo protegen todas las rutas; `manage` no concede consulta implícita. Los endpoints analíticos están limitados a veinte solicitudes por minuto. No hay comandos de aprobación, cambios de cantidad, escrituras ERP ni recálculo desde el dashboard.

La versión inicial 7.4.1 utilizaba el esquema hasta `000006`. La versión integrada con D2 requiere las evoluciones hasta `000009`. Si falta, el resumen devuelve un diagnóstico explícito y no migra automáticamente. No se inspeccionaron ni migraron bases reales para esta fase. Se mantienen pendientes la revisión visual manual, los motores reales y la medición de rendimiento con carga representativa. No se implementa Fase 7.4.2.

## Verificación focalizada

- `tests/Dashboard741Test.php`: SQLite efímera, filtros, estados, únicos, paginación, calendario, versiones, calidad desconocida, lectura sin escritura, meses sin detalle diario y diagnóstico de migración ausente.
- Consumidor: `Dashboard741HttpTest.php`, con sesión, permisos y dos conexiones SQLite temporales; `replenishment741.test.mjs`, con renderizado Vue virtual.
- Sintaxis PHP 8.1 comprobada sobre archivos nuevos/modificados; ejecución funcional con PHP 8.2. No se repite la matriz histórica de Laravel ni se amplían restricciones Composer.

## Consulta por publicación desde el ajuste 7.3.3-E

La estructura actual requiere las evoluciones hasta 000009. El resumen operativo cuenta cabeceras por estado, líneas pendientes, activas y terminadas, errores recuperables y de intervención, procedencia estadística y decisiones pendientes. Un mismo producto puede aparecer en varias líneas. Los totales por estado, procedencia y decisión describen dimensiones distintas.

Al elegir un producto se recupera una sola fotografía comercial. El lector puede paginar versiones, elegir una unidad para la gráfica y desplegar cada año y mes. La tabla conserva todas las unidades. Las semanas van de lunes a domingo y se recortan al mes y al intervalo consultado. La leyenda de Chart.js permite mostrar u ocultar cada señal. Las etiquetas y la tabla distinguen períodos parciales, desconocidos y fuera del corte.

La evidencia de una línea identifica su generación y publicación. Los cambios posteriores del maestro conservan intactas las consultas históricas explícitas. Consulte la [conciliación del ajuste](ajuste-733.md#e--conciliación-y-consulta-comercial) y el [orden de despliegue](migraciones.md#despliegue-del-ajuste-733).

La comprobación específica de E incluye `CommercialStatisticsTest` y `Dashboard741Test` del paquete. En consult-web, `Dashboard733ETest` recorre el adaptador Orbis sobre SQLite, la materialización, las tres estrategias guardadas, el dashboard, las semanas y la referencia de propuesta. Comprueba también el diagnóstico de esquema incompleto. `replenishment741.test.mjs` cubre el selector de unidad, la identidad de la publicación en la expansión y la lectura de evidencia en renderizado virtual.
