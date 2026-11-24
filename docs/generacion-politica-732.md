# Generación por política — Ajuste 7.3.2

La generación produce propuestas internas revisables; no emite órdenes, no certifica entradas y no modifica el ERP. La selección y la política se congelan expresamente. No se inicia la Fase 7.4.

## Aplicación de la política

PolicyDrivenPlanningService reutiliza disponibilidad normalizada, SupplierLeadTimeQuery/Resolver, ForecastQuery, ReplenishmentCalculator, ReplenishmentQuery y el flujo PurchaseProposalService. El balance común se concentra en ReplenishmentCalculator::balance; el simulador mensual conserva sus reglas.

- analysis_days delimita exactamente la historia usada para demanda. La historia visual adicional y los 360 días descriptivos de tendencia no amplían esta ventana.
- minimum_history_days exige cobertura comercial completa y suficiente antes de usar pronóstico o heurística.
- target_coverage_days determina el tramo civil posterior al plazo. Protección total = plazo redondeado hacia arriba a días civiles + cobertura. El consumo durante el plazo se descuenta una sola vez.
- forecast_horizon_days limita la elegibilidad temporal. Los pronósticos se solicitan sólo cuando evaluación, recepción y fin coinciden con límites mensuales y el horizonte cabe en seis meses. No se divide un pronóstico mensual entre días.
- seasonal_minimum_years se pasa explícitamente a ForecastQuery cuando corresponde, sin cambiar configuración global.
- default_lead_time_days sólo interviene detrás del manual activo y del historial certificado utilizable del destino. Nunca se adopta otra política por su posición en el catálogo.
- service_level es informativo; no produce seguridad estadística. Seguridad vacía conserva NULL; cero manual requiere entrada expresa.
- active se verifica al iniciar. La ejecución conserva valores, identificación, fechas y actor de la política congelada.

Demanda por tramo: pronóstico utilizable compatible; después heurística autorizada historical_daily_rate_heuristic; después tasa manual expresa; finalmente desconocido. La heurística usa FAC verificada / días verificados, nunca ventas netas negativas como demanda futura. La multiplicación precede a la división; el cociente se conserva a doce decimales HALF_UP con regla declarada. Un tramo vacío tiene consumo cero por duración cero, sin inferir ceros históricos.

La tendencia compara 30/90/180 días con períodos anteriores no superpuestos dentro de cada pareja. Crecimiento o descenso requieren concordancia de dos escalas y de dos bloques recientes independientes de 30 días frente a los mismos bloques de los noventa días anteriores. Esto impide concluir descenso a partir de un solo mes bajo. Se conservan frecuencia, rango diario, exclusiones y advertencias; no es una prueba estadística y confidence permanece NULL. La intermitencia descriptiva usa frecuencia menor a 0,20. Las escalas comparten observaciones entre sí y no constituyen réplicas independientes.

ExtendedCoverageEvaluator conserva escenario central y, sólo cuando se introduce, una tasa conservadora manual. No son intervalos predictivos. Una posible sobrecobertura muestra advertencia y no excluye automáticamente el producto. Los estados operativos se separan de draft/in_review/approved/rejected/cancelled.

## Historial y calidad

SalesHistoryBreakdownService consulta un año o mes bajo demanda, preferentemente desde ObservedDemandStore. El consumidor autoriza producto/proveedor, fija destino y limita frecuencia. Una lectura directa requiere selección expresa y no publica caché. La comparación opcional consulta también el período del año anterior; no se precargan diez años ni todos los productos.

Las semanas son lunes-domingo intersectadas con el mes y recortadas al corte operativo. Un día aparece exactamente una vez. FAC, NCC, NDC y neto se conservan separados: neto = FAC − NCC + NDC. Meses/años parciales se etiquetan; días ausentes, versiones o zonas incompatibles mantienen cantidades desconocidas. Cero sólo se publica con observación completa. Las variaciones comparan tasas diarias de períodos completos, de igual unidad, con cobertura íntegra y denominador anterior positivo; en otro caso son NULL. La caché puede estar desactualizada y no acredita inventario histórico.

## Costo y presupuesto

ProductCostSource es opcional. ProductCostData requiere decimal textual, moneda, unidad, fuente, momento, calidad y advertencias. Sólo un costo verificado por la misma unidad base permite costo de presentación = costo base × factor y subtotal = costo base × unidades decididas. No hay tipos flotantes ni conversión de monedas.

El adaptador pertenece a consult-web. Su lista cerrada de columnas es dolarrep, costorepos, costocompr y ultcospro; ninguna procede de HTTP. La selección inicial es I00001.dolarrep. Un valor leído y el nombre de columna no prueban USD ni semántica por unidad base: hasta verificar ambas, el costo utilizable permanece NULL. El consumidor configura moneda, evidencia y verified_per_base_unit sólo después de esa verificación. No se utilizan precios de venta.

El presupuesto es opcional. Se suman decisiones incluidas por moneda, conservando conteo de costos faltantes y decisiones pendientes. No se presenta un total incompleto como definitivo ni se distribuye presupuesto automáticamente. El usuario puede reducir cantidades respetando mínimos/múltiplos o excluir con motivo. Las columnas monetarias heredadas son resúmenes a seis decimales; evidencia y decisiones conservan importes exactos.

## Selección, ejecución y guardado

El navegador del consumidor filtra relaciones activas por proveedor, código exacto, descripción, unidad base, presentación y estado de configuración. La ordenación global entre motores reutiliza bloques y archivos temporales; no usa joins cruzados. Los tamaños son 25/50/100/200, con 25 predeterminado; las llamadas heredadas conservan su contrato.

Seleccionar todos envía filtros y total confirmado, no miles de identificadores a Vue. El servidor congela hasta diez mil relaciones; la selección manual admite dos mil. Si el total cambia, la creación se revierte y exige revisar el conjunto. No existe instantánea distribuida entre ERP y configuración: cambios concurrentes que conserven el mismo total pueden cambiar las identidades finalmente congeladas, que quedan auditadas. La memoria de catálogo se limita a bloques de cien; congelar muchas identidades requiere transacción propia y tiene costo proporcional al conjunto.

ProposalGenerationService registra UUID, huella de intención, proveedor/destino, actor, versión, política, filtros, opciones, selección, estados, resultados originales y eventos inmutables. Cada petición procesa un producto. La interfaz reanuda secuencialmente con pausa entre peticiones, sin depender de una cola adicional ni mantener todo el cálculo en una sola petición. Cerrar la pantalla detiene nuevos bloques; el último confirmado permanece. Reanudar requiere la identidad de ejecución. Si cambia el día operativo, se necesita una ejecución nueva para calcular existencias actuales.

Cada producto requiere una decisión expresa de inclusión o exclusión y motivo. Cantidades desconocidas sólo pueden incluirse mediante decisión manual y presentación vigente. Los mínimos/múltiplos se comprueban sin redondear decisiones humanas. Exclusiones y restauraciones se conservan en eventos, aunque no produzcan una línea final.

El guardado crea una única cabecera y añade un producto por transacción. Cada línea calculada conserva su resultado individual y evidencia original; ajustes posteriores quedan diferenciados. Mientras status=saving, el flujo normal rechaza editar, revisar o aprobar la propuesta parcial. Los reintentos usan UUID derivados estables y no duplican líneas. Se revalidan cantidades, presentación, inventario, pendiente, plazo y costo antes de publicar una línea calculada. Un conflicto revierte ese bloque, conservando los anteriores. Puede cancelarse expresamente la construcción parcial con motivo, preservando cabecera, líneas y auditoría, para iniciar otra ejecución. No se promete una transacción distribuida entre fuentes ni atomicidad de toda la propuesta entre peticiones.

La propuesta queda editable mediante el flujo existente sólo al terminar todos los bloques. La aprobación sigue siendo humana y requiere el permiso separado existente. Se conservan coincidencias con propuestas abiertas, déficit proyectado y las hipótesis. Una aprobación no transforma una simulación en pronóstico certificado ni en mercancía entrante.

## Persistencia y auditoría

La migración evolutiva 000006 añade actores nullable a políticas y las tablas replenishment_policy_events, replenishment_generations, replenishment_generation_items y replenishment_generation_events. No modifica cantidades ni tablas ERP ni adopta propuestas anteriores. Todas las operaciones utilizan PersistenceConnection y FK NO ACTION sólo entre tablas propias.

CommercialConfiguration acepta un actor opcional del consumidor. Los transportes web/API suministran el usuario autenticado; no admiten created_by/updated_by enviados como atributos comerciales. Las llamadas internas sin usuario conservan NULL explícito. Los eventos registran antes/después y creación, edición, activación o desactivación. La administración muestra los últimos cincuenta eventos y el total conservado. Las propuestas exponen creador, último editor, remitente, revisor y aprobador derivados de sus eventos, con nombres resueltos por el consumidor.

Aplicación manual autorizada posteriormente:

~~~console
php artisan replenishment:migrate
~~~

No se ejecutó este comando en consult-web. La instalación existente conserva su repositorio dedicado. El envoltorio estándar es optativo y no se publica ni registra automáticamente. El rollback elimina esta ampliación y su auditoría; requiere respaldo antes de cualquier uso real. No elimina las propuestas existentes.

## Verificación y límites

Pruebas del paquete en PHP 8.2/Laravel 12 y del consumidor en PHP 8.2/Laravel 10, con SQLite temporal. Sintaxis del paquete comprobada con PHP 8.1. No se repitió la matriz completa ni se afirma ejecución nueva de Laravel 11. El consumidor verifica calendario, permisos, aislamiento, costo, políticas, selección, UUID, versiones, exclusión/restauración, guardado único, evidencia obsoleta, cancelación y rollback evolutivo. Vue se comprueba con renderizador virtual y compilación Vite, sin navegador.

La evidencia real acotada del consumidor consultó únicamente SELECT para dos productos configurados de P0861; no publicó métricas ni propuestas. Esto no verifica migraciones, escrituras o concurrencia de SQL Server/MySQL. Pendientes: verificación semántica del costo, revisión visual humana, pruebas de escritura/migración en motores reales aislados y concurrencia entre procesos. La política no estima demanda perdida, seguridad estadística ni fechas ciertas de agotamiento.


## Corrección 7.3.2.1 — Cobertura extendida

El horizonte predeterminado es individual: plazo resuelto (redondeado hacia arriba a días civiles) + target_coverage_days. Con cobertura 90 y plazos 30/60/90/120 se evalúan 120/150/180/210 días. Vue deja vacía la sustitución y no envía extended_days en ese caso; muestra protección automática y horizonte evaluado por producto. Si falta plazo, el horizonte automático permanece desconocido.

La evaluación coverage-2.0 reutiliza la suma exacta de demanda durante plazo y cobertura, sin multiplicar una tasa redondeada ni restar el consumo dos veces. El balance de cada escenario es disponibilidad normalizada + entradas certificadas aplicables − seguridad explícita − demanda acumulada. Un escenario conservador explícito se evalúa por separado sobre el mismo horizonte. Sólo hay candidato si el central y, cuando exista, el conservador resultan suficientes. La discrepancia produce «Revisión necesaria». Seguridad, pendientes, calendario o demanda desconocidos impiden una alerta favorable; la hipótesis assume_no_incoming no certifica ausencia.

Las entradas se leen del lote normalizado existente, sin consultas duplicadas: se cuentan una sola vez, únicamente entre evaluación inclusiva y final exclusivo, en la zona operativa. Entradas vencidas, sin fecha, lotes no certificados o totales incoherentes conservan desconocimiento. El balance no garantiza cobertura intrahorizonte ni ausencia de agotamientos. La seguridad manual y la demanda exploratoria mantienen sus hipótesis y calidad originales.

Una sustitución manual distinta de la protección exige demanda para el nuevo intervalo exacto: pronóstico mensual compatible utilizable dentro del límite de la política, o tasa diaria manual expresamente suministrada. No se prolonga automáticamente la heurística histórica. Sin respaldo adicional, la demanda del horizonte manual permanece NULL. La tasa conservadora manual por sí sola no completa una demanda central desconocida.

No se reescriben generaciones ni propuestas anteriores. Un extended_days ya almacenado conserva su valor, incluso si era 180; no se puede distinguir retroactivamente una entrada manual del predeterminado anterior. Los resultados sin assessment_version se presentan como históricos sin aplicarles el resaltado favorable nuevo. La firma anterior evaluate se conserva, pero no acredita cobertura favorable sin seguridad y pendientes; la integración actual usa evaluateAccumulated. No cambian migraciones, tablas, cantidades de compra ni el flujo aprobable.

Verificación focalizada de 7.3.2.1: nueve pruebas PHP (104 aserciones), incluidas dos comprobaciones puntuales de compatibilidad; cuatro pruebas frontend, sintaxis PHP 8.1 y Vite satisfactorios. No se repitieron suites completas ni se utilizaron bases reales. La visualización se comprobó con renderizado virtual, sin revisión manual en navegador.


## Corrección 7.3.2.2 — Hipótesis explícita de entradas

Esta corrección sustituye la restricción de 7.3.2.1 que impedía evaluar cobertura con pendientes desconocidos incluso cuando se autorizaba simular cero. Primero se usan las entradas certificadas con calendario válido del horizonte. Sólo si el lote original tiene status=unknown y assume_no_incoming=true se permite cero para el diagnóstico. Una cantidad positiva certificada sin calendario válido no se descarta ni se sustituye por cero. Sin autorización, el valor considerado sigue siendo NULL.

El formato nuevo coverage-2.1 conserva certified_incoming en NULL bajo hipótesis y añade incoming_considered, incoming_source, incoming_assumption, diagnostic_quality y warnings. La procedencia/hipótesis se identifica como explicit_zero_incoming_assumption. No se cambia el estado original del lote ni se falsifica certified_absent; PurchaseSource y la publicación de verified_incoming permanecen sin modificaciones. La autorización nunca sustituye entradas certificadas disponibles.

Una evaluación completa es exploratoria, incluso si utiliza datos certificados, porque conserva las hipótesis comerciales de demanda y seguridad. Información insuficiente en seguridad, demanda, horizonte o escenario conservador sigue bloqueando la candidatura. Las discrepancias mantienen «Revisión necesaria». La sobrecobertura bajo cero supuesto se etiqueta expresamente como exploratoria, sin exclusión automática ni garantía de ausencia de agotamientos.

El detalle visible muestra cantidad considerada, procedencia, hipótesis, balances central/conservador, calidad y advertencias. Las generaciones y propuestas existentes no se reescriben: coverage-2.0 sigue interpretándose con su evidencia original; los nuevos campos no se inventan para históricos. No hay nuevas migraciones ni cambios de tablas.

Verificación focalizada: cuatro pruebas PHP, 52 aserciones, y tres pruebas frontend satisfactorias, incluyendo renderizado del detalle dentro de la pantalla real con transporte controlado. No se ejecutaron suites completas, migraciones reales ni lecturas ERP. El renderizado virtual no equivale a revisión manual en navegador.

La generación registra la etapa interrumpida, conserva publicaciones fijadas y distingue conflictos de reserva, versión y configuración. El informe técnico incluye el protocolo reproducible y las medidas de lectura y escritura. [Publicación estadística y recuperación](publicacion-estadistica.md)

La acción **Revisar parámetros** abre una revisión independiente para cada producto calculado, incluida una generación con propuesta guardada. [Reglas de parámetros, evidencia y decisiones](parametros-inventario.md)
