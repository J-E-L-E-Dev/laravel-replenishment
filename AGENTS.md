# Reglas de desarrollo

## Alcance y separación

- Mantener el paquete independiente de cualquier aplicación consumidora y distribuible por Composer.
- No incorporar modelos, nombres de tablas, consultas ni reglas específicas de OrbisNET en el núcleo.
- Los adaptadores pertenecen al consumidor y deben implementar contratos explícitos.
- No interpretar `C02091.unidad` como presentación comercial del proveedor. No inferir empaques, conversiones ni presentaciones inexistentes en el ERP.
- No convertir la ausencia de información en cantidades cero ni confundir existencia física con disponibilidad.
- No ejecutar migraciones sobre bases reales ni modificar tablas existentes para verificar el paquete.
- Resolver toda persistencia propia mediante PersistenceConnection: modelos, esquema y futuras transacciones. No usar DB::transaction ni Schema sin destino explícito para operaciones del paquete.
- Conservar REPLENISHMENT_DB_CONNECTION como nombre de una conexión Laravel existente; si falta, usar database.default. No inferir conexiones a partir de drivers ni de adaptadores ERP.
- No registrar migraciones propias en el migrador global. Usar replenishment:migrate y su opción --rollback, con replenishment_migrations en la misma conexión que las tablas.
- No modificar la conexión predeterminada global ni redirigir modelos propios mediante Model::on o setConnection. Las conexiones ERP pertenecen exclusivamente a los adaptadores del consumidor.
- No adoptar automáticamente tablas o registros de migración de instalaciones antiguas: exigir conciliación verificada.
- La Fase 1 está aprobada, incluido el Junction de Windows; no sustituirlo por otro mecanismo.
- Las Fases 1, 2, 2.1, 3.1, 3.2A y 3.2B están aprobadas. La Fase 4.1 autoriza ventas observadas, series temporales y caché analítica propia. No iniciar otra fase, estacionalidad, pronósticos ni reposición sin autorización. Conservar disponibilidad de 3.1 y las limitaciones históricas de 3.2A/3.2B.
- Mantener las fuentes ERP en adaptadores del consumidor. El cálculo de disponibilidad sólo recibe datos normalizados y nunca usa la conexión de persistencia.
- Conservar saldo negativo, cantidades desconocidas como NULL e identidades de reserva sin duplicados. No clasificar automáticamente reservas antiguas sin categoría.
- Las claves foráneas sólo relacionan tablas propias; usar NO ACTION y conservar históricos. No vincular modelos User del consumidor.
- Usar los modelos para escrituras de dominio: actualizaciones masivas, upsert y SQL directo omiten validaciones e inmutabilidad Eloquent.
- No introducir float/double en cantidades, costos ni conversiones. En SQLite conservar decimales como texto exacto; en otros motores usar DECIMAL con precisión y escala explícitas.
- Los parámetros comerciales no definidos permanecen NULL. Registrar nuevas revisiones de presentaciones y conservar sus instantáneas históricas.

- Conservar signos originales y separar efecto físico de demanda comercial; no duplicar el signo de devoluciones ni sumar ajustes como ventas.
- Leer históricos por bloques con identidades verificadas y documentos relacionados por componentes separados. Conservar limitaciones de fecha, orden, unidades y existencia resultante; no inferir continuidad ni conversiones.

- Separar conciliación observada de pendiente comercial confirmado. NULL en cantidad recibida o facturación, y el estado P, no prueban ausencia de recepciones ni obligación pendiente.
- Vincular recepciones mediante evidencia documental, producto, proveedor, fecha y unidad. Con varias líneas candidatas conservar granularidad orden/producto; no asignar arbitrariamente.
- Sumar efectos ENT/NDP/NCP ya firmados una sola vez; una devolución no demuestra reapertura de la obligación. pendingFor debe fallar explícitamente si no se verifica el pendiente completo.
- No modificar las discrepancias históricas de existencias mediante supuestos. No certificar continuidad, cierres ni plazos definitivos a partir de muestras incompletas.

- Clasificar eventos comerciales explícitamente como sale, return o return_reversal; no deducirlos del signo. No excluir ventas válidas por discrepancias de existencias.
- Usar días calendario completos y cortes semiabiertos en la zona operativa. Cero observado no significa demanda potencial cero ni disponibilidad confirmada.
- Una fuente incompleta o identidades duplicadas no pueden publicar totales parciales como definitivos. Mantener desconocidos en NULL y calidad comercial separada de inventario.
- La caché diaria es reconstruible y admite upsert por bloques sólo después de validar cada fila mediante DailySale. Caché y nueva métrica se publican atómicamente mediante PersistenceConnection; las métricas históricas permanecen inmutables.

## Documentación obligatoria en todas las fases

- Toda la documentación y los comentarios deben estar en español.
- Cada clase, interfaz, trait y método público, protegido o privado debe tener PHPDoc en español, incluidos pruebas, constructores y componentes auxiliares.
- Explicar responsabilidad, parámetros, resultados, excepciones aplicables y reglas de negocio relevantes; los constructores no declaran retorno.
- Mantener nombres técnicos de clases, interfaces, métodos, namespaces y variables en inglés.
- Actualizar arquitectura, integración y evidencia de compatibilidad cuando cambie el comportamiento.

## Compatibilidad y comprobaciones

- Conservar sintaxis compatible con PHP 8.1. Laravel 11 y 12 requieren PHP 8.2 o superior.
- Verificar cada versión mayor de Laravel antes de ampliar las restricciones de Composer.
- Distinguir versiones ejecutadas, resolución de dependencias y comprobaciones pendientes; no presentar una matriz planificada como ya verificada.
- Ejecutar pruebas focalizadas del paquete en SQLite temporal, sin servicios externos ni bases reales. La migración y su reversión deben cubrirse.
- No declarar verificado SQL Server 2017 mediante SQLite o compilación de gramática: hace falta ejecutar pruebas en una instancia aislada autorizada.
- No introducir dependencias de producción si sólo se necesitan para pruebas.
- No añadir rutas locales ni repositorios path al manifiesto distribuible del paquete.
- En el consumidor, limitar cambios a integración Composer, adaptadores de lectura, configuración, consulta CLI, pruebas y documentación autorizadas; no actualizar dependencias ajenas ni alterar funcionalidades comerciales existentes.




## Reglas añadidas por la Fase 4.2

- Conservar saldos puntuales separados de calidad de observación, continuidad y duración acreditada. Un cero observado no acredita un día agotado.
- No extrapolar antes/después de anclajes ni atravesar discrepancias, unidades no verificadas, orden ambiguo o cobertura documental desconocida.
- InventoryCoverageData sólo representa cobertura previamente comprobada por quien lo aporta; no generarlo automáticamente por lectura exitosa ni por referencia textual. El adaptador Orbis actual no certifica cobertura.
- Mantener disponibilidad comercial histórica y demanda perdida desconocidas; no usar reservas vigentes para reconstruir reservas antiguas ni modificar ventas por discrepancias físicas.
- El diagnóstico de Fase 4.2 no persiste resultados. No iniciar otra fase, pronósticos o reposición sin autorización.

## Reglas añadidas por la Fase 5.1

- La Fase 5.1 autoriza estacionalidad mensual de ventas observadas; no pronósticos, demanda perdida ni reposición. No iniciar Fase 5.2 sin autorización.
- Usar ventas brutas verificadas como señal temporal; conservar devoluciones, reversiones y netos en sus fechas sin reasignaciones.
- Normalizar tasas diarias sólo en años completos, comparables y con actividad suficiente; no anualizar años parciales ni transformar meses desconocidos en cero.
- Conservar opciones, años incluidos/excluidos, magnitudes originales y sensibilidad a extremos. Recurrencia descriptiva no equivale a significación estadística; confidence permanece NULL.
- Rechazar la publicación de reconstrucciones comerciales incompletas; no degradar caché previamente verificada ni publicar métricas parciales.
- Persistir patrones nuevos y auditoría vinculada atómicamente mediante PersistenceConnection, sin sobrescribir históricos. No crear migraciones adicionales sin necesidad demostrada.

## Reglas añadidas por la Fase 5.2

- La Fase 5.2 autoriza pronósticos mensuales de verified_gross_sales y validación retrospectiva, no compras, stock de seguridad ni demanda perdida. No iniciar Fase 6.1 sin autorización.
- Fijar corte mensual explícito. Entrenar, seleccionar parámetros y evaluar exclusivamente con información anterior al corte; recalcular tendencia y estacionalidad dentro de cada origen retrospectivo.
- Separar los objetivos de selección de la reserva temporal de validación, también a horizontes largos. No cambiar retrospectivamente el método elegido mirando esa reserva.
- Comparar métodos sobre los mismos pares; conservar media sencilla si no se demuestra mejora. No usar factores estacionales posibles, inestables o insuficientes.
- Los meses comerciales desconocidos no son ceros; no saltar huecos del entrenamiento reciente. Conservar cantidades desconocidas cuando no haya evidencia suficiente.
- Distinguir usable, exploratory e insufficient sin convertir etiquetas en porcentajes de confianza. lower_bound, upper_bound y confidence siguen NULL mientras no se calibren intervalos.
- Publicar pronósticos nuevos y auditoría relacionada atómicamente mediante PersistenceConnection. Los históricos exploratorios no deben consumirse como si estuvieran validados; leer su categoría en forecast_audit.
- Conservar las limitaciones de reservas/devoluciones, inventario histórico y revisiones retroactivas. El pronóstico FAC no equivale a consumo neto definitivo para abastecimiento.

## Reglas añadidas por la Fase 6.1

- La Fase 6.1 autoriza diagnóstico y resolución de plazos de proveedores; no compras sugeridas, reposición ni Fase 6.2 sin autorización.
- Reutilizar PurchaseReconciler sin normalizar signos nuevamente. Separar primera recepción observada, completitud histórica, cobertura de cantidades y cierre comercial.
- LeadTimeCoverageData sólo representa verificación externa previamente realizada. No derivarla de verified, observedCompletionAt, una referencia textual ni una lectura exitosa. Orbis no la genera.
- Usar días civiles operativos, evidencia documental y estadísticas por producto/proveedor/destino; no inferir cero, incumplimiento ni plazo completo de órdenes sin recepción.
- Priorizar manual explícito de relación activa, historial certificado utilizable y política explícita. El manual es general producto-proveedor; no atribuirle confianza ni vigencia contractual basada en updated_at.
- Persistir únicamente estadísticas documentadas con destino y auditoría, nunca manuales como históricos. Mantener métricas inmutables y distinguir exploratorias de utilizables. Históricos sin destino no pertenecen a ninguna ubicación inferida.
- La CLI histórica debe funcionar sin tablas propias y advertir la indisponibilidad de manuales; no crear relaciones ni migrar automáticamente. Toda publicación y migración usa PersistenceConnection.

## Reglas añadidas por la Fase 6.2

- La Fase 6.2 autoriza diagnóstico de reposición y simulaciones; no propuestas aprobables ni órdenes oficiales, interfaces Vue o siguiente fase sin autorización.
- Reutilizar disponibilidad normalizada; no descontar reservas otra vez ni usar reservas actuales para fechar inventario histórico. Conservar negativos y NULL.
- Trabajar con meses completos. No distribuir pronósticos mensuales diariamente; exigir consumo previo explícito para simular tramos parciales. No usar pronósticos insufficient ni promover exploratory a recommended.
- PurchaseSource debe certificar todo el lote; distinguir ausencia certificada de pendiente desconocido. Cero supuesto requiere hipótesis explícita y nunca se persiste como verified_incoming. Cantidad sin calendario no acredita cobertura a una fecha.
- Seguridad manual implica simulación. SafetyStockEvidenceData sólo representa calibración externa previamente comprobada para producto, destino, unidad y protección exacta; ninguna referencia textual ni nivel de servicio la demuestra. Orbis y CLI no la generan.
- Mantener presentación explícita, activa y revisión vigente, con factor, mínimo y múltiplo conocidos. Necesidad cero no impone mínimo. No inferir conversiones ERP.
- Estados simulation e insufficient_data conservan sus hipótesis y motivos; una simulación de necesidad cero sigue siendo simulation. La fecha objetivo es orientativa, no compromiso ni optimización diaria.
- Publicar únicamente por solicitud explícita, añadiendo ejecución y resultado atómicamente, sin mutar históricos. Conservar fotografía comercial y cantidades exactas en auditoría; columnas de seis decimales son resúmenes con redondeo declarado.

## Reglas añadidas por la Fase 7.1

- La Fase 7.1 autoriza configuración comercial propia y API de integración; no pantallas, importaciones masivas, aprobación de recomendaciones ni órdenes oficiales. No iniciar Fase 7.2 sin autorización.
- Gestionar configuración mediante CommercialConfiguration y modelos existentes, bajo PersistenceConnection. Validar producto/proveedor mediante contratos y conservar identidades textuales, sin maestros ERP duplicados.
- Cambiar factor, mínimo o múltiplo añade revisión; no modificar identidades de presentación ni borrar configuraciones desde la administración. Verificar vigencia dentro de la misma relación/unidad, sin elegir otra presentación ni volver automáticamente a una revisión anterior.
- No inventar unidades, conversiones, preferencias, plazos ni políticas. Cero manual debe ser explícito; NULL conserva desconocimiento. Las políticas incompletas permanecen inactivas.
- ReplenishmentAccess usa Sanctum y permisos Spatie separados de consulta/administración, con capacidad del token cuando corresponde. No incorporar excepciones personales ni modificar globalmente Gate::before para este módulo. Conservar documentado el conflicto existente.
- Ninguna referencia de calibración externa sin autenticar autoriza aprobar recomendaciones; la administración no acepta ni produce aprobaciones.
- No migrar ni insertar fixtures en bases comerciales. Mantener prueba MySQL aislada expresamente pendiente mientras no exista un destino identificado; no declararla verificada mediante SQLite o SELECT al ERP.

## Reglas añadidas por la Fase 7.2

- La Fase 7.2 autoriza la interfaz de administración y simulación en el consumidor. No iniciar Fase 7.3, propuestas aprobables ni órdenes oficiales sin autorización.
- La interfaz usa sesión web Jetstream, CSRF y permisos Spatie directos separados de consulta y administración. No usar el Gate personal como autorización del módulo ni generar tokens Bearer para Vue.
- Invocar CommercialConfiguration y ReplenishmentQuery desde los controladores del consumidor, sin solicitudes HTTP internas ni cálculos comerciales en JavaScript. Conservar decimales como cadenas y desconocidos como NULL.
- Consultar maestros por código exacto y paginar configuraciones propias en lotes acotados. No descargar catálogos completos ni modificar revisiones obsoletas; ofrecer recarga ante conflictos.
- Separar navegación/formularios Inertia de respuestas JSON de consultas y simulación. Preservar valores inválidos, comprobar permisos en servidor y confirmar desactivaciones.
- La interfaz sólo evalúa disponibilidad actual en la fecha operativa de hoy; no aplicar reservas vigentes a fechas históricas o futuras. Acotar horizonte, entrenamiento y frecuencia de consultas.
- No guardar simulaciones, migrar al abrir pantallas ni escribir en el ERP. Mantener MySQL aislado y revisión visual manual pendientes hasta su comprobación expresa.

## Reglas añadidas por el ajuste 7.2.1

- Se autoriza carga inicial de relaciones y publicación optativa de envoltorios estándar para instalaciones nuevas. Esta excepción no autoriza mezclar historiales ni registrar automáticamente rutas en el migrador global. Conservar el historial dedicado de instalaciones existentes; no publicar ni migrar consult-web automáticamente.
- Reutilizar una única definición de esquema con comprobación de que el repositorio de migraciones y las tablas usan la conexión propia. No adoptar historial de otra modalidad.
- Normalizar únicamente entradas de proveedor a mayúsculas sin espacios exteriores; persistir la identidad canónica verificada. No alterar semántica de códigos de producto.
- El último plazo activo de un proveedor sólo es una sugerencia editable para nuevas relaciones. No reemplazar ediciones manuales ni inferir preferencia, notas o estado.
- Las importaciones genéricas usan SupplierProductSource, UUID auditable, vista previa, confirmación, cursor/versionado durable y transacciones por bloques. No sobrescribir relaciones existentes ni sustituir preferencias. No importar presentaciones ni conversiones.
- Las consultas de provecompr e I00101 pertenecen al adaptador del consumidor y son de sólo lectura. No usar proverepos como sustituto. Separar unidad base real de unidad comercial.
- Preservar personalizaciones de ModuleLayout, botones, menú y permisos del usuario. Mantener sesión, CSRF y permisos directos; carga masiva requiere manage y no concede view.
- No iniciar Fase 7.3, ejecutar migraciones reales ni modificar algoritmos de reposición. Documentar límites de concurrencia, lectura viva del catálogo y verificación pendiente de motores reales.


## Reglas añadidas por el ajuste 7.2.2

- Se autoriza crear presentaciones comunes para una selección explícita de hasta cien relaciones, con confirmación y validación de unidad base. Esto no autoriza inferir presentaciones desde el ERP ni generarlas con la importación de proveedores.
- BulkPresentationCreator reutiliza CommercialConfiguration y PersistenceConnection. Revalida relaciones/maestros, bloquea el lote ante unidades incompatibles o referencias inválidas y omite toda presentación existente sin añadir revisiones. Un fallo de escritura revierte todas las altas del lote.
- El formato decimal de interfaz sólo elimina ceros fraccionarios finales en cadenas; no cambiar casts, columnas, precisión ni cálculos.
- La interfaz numérica usa totales completos y LengthAwarePaginator, con 25 registros por página. No presentar cursores de registros inspeccionados como páginas reales ni truncar búsquedas a 500 filas.
- Resolver descripciones entre motores mediante bloques y materialización temporal acotada en memoria, sin joins cruzados ni escrituras ERP. Ante fallos no presentar totales parciales. Documentar costo y falta de instantánea distribuida.
- Reutilizar PurplePaginationCurrent sin cambiar su contrato global. Conservar API/opciones heredadas por cursor y las personalizaciones del usuario.
- El patrón xxx-xxx es ayuda visual exclusiva del consumidor, con debounce y descarte de respuestas antiguas. No normalizar destructivamente códigos de producto en backend ni restringir códigos especiales.
- No crear migraciones para este ajuste, modificar motores matemáticos ni iniciar Fase 7.3. La revisión manual y la verificación de motores reales deben distinguirse de pruebas temporales.


## Reglas añadidas por el ajuste 7.2.3

- Se autoriza el simulador guiado del consumidor y correcciones de usabilidad; no modificar motores, fórmulas, pronósticos ni iniciar Fase 7.3.
- Mostrar procedencia ERP, configuración propia, hipótesis y desconocidos. Usar la unidad base verificada como sólo lectura e invalidarla al cambiar identidades; nunca inferirla de la presentación.
- Filtrar presentaciones por relación antes de paginar y seleccionar automáticamente sólo una revisión válida única. Cancelar y descartar respuestas anteriores; conservar el contrato heredado de opciones.
- Evaluar hoy y objetivos mensuales completos dentro del límite del transporte. Las sugerencias de fecha son optativas, nunca compromisos u optimizaciones. Permitir diagnósticos incompletos con validación final en servidor.
- Mantener cero explícito separado de NULL. Formatear cantidades con coma mediante cadenas y enviar decimales exactos con punto. No aplicar formato decimal a códigos, fechas ni textos ni hacer aritmética comercial en Vue.
- Marcar resultados anteriores al cambiar cualquier parámetro y conservar auditoría, hipótesis y advertencias. Las explicaciones numéricas sólo sustituyen operandos conocidos devueltos por el motor.
- Refrescar filas y paginación tras altas confirmadas, deduplicando notificaciones y conservando filtros, edición, UUID y resúmenes. No refrescar por vista previa ni repetir escrituras.
- Una política activa no implica que el simulador la utilice; exigir vinculación explícita. El porcentaje de servicio no calcula seguridad ni certifica resultados.
- Conservar personalizaciones y configuraciones reales. Pruebas focalizadas en SQLite y renderizado virtual no acreditan revisión visual manual ni motores reales.

## Reglas añadidas por la Fase 7.3

- La Fase 7.3 autoriza propuestas internas, captura explícita desde simulador, cantidades manuales, revisión y aprobación humana. No autoriza órdenes oficiales, escrituras ERP, pendientes certificados ni Fase 7.4.
- Mantener servicios de propuestas en el paquete y sesión/permisos/transporte/Vue en el consumidor. Reutilizar cálculo decimal existente y validar maestros mediante contratos.
- Vincular cada línea calculada a su resultado individual; mantener evidencia original, hipótesis y revisiones. Cabecera no inventa una ejecución común. Manuales y ajustes no se convierten en pronósticos certificados.
- Recalcular y verificar evidencia del simulador antes de persistir; la acción expresa de agregar es la excepción autorizada a la consulta sin persistencia. Rechazar resultados desactualizados y no confiar en cantidades de Vue.
- Una decisión humana incompatible con mínimos o múltiplos se rechaza sin redondeo. No inferir unidades, precios ni monedas. Costo estimado conserva semántica por unidad base; sin fuente vigente queda NULL.
- Usar versión, bloqueo, UUID idempotente y eventos inmutables bajo PersistenceConnection. Conservar retiradas y estados finales. No adoptar propuestas antiguas sin auditoría conciliada.
- Exigir permisos directos separados de view/manage/approve, actor autenticado, motivo y confirmación según acción. No asignar approve automáticamente ni aceptar status libre desde HTTP. Revalidar huella de advertencias antes de aprobar.
- Advertir coincidencias entre propuestas abiertas y déficit proyectado; no inferir ventas perdidas, fecha de agotamiento ni sumar dos veces el déficit. Una aprobación interna no es una orden ni inventario entrante.
- Permitir conservación explícita de revisión histórica válida al aprobar; nuevas selecciones requieren vigencia. Para cambiar cantidades en revisión, devolver a borrador con motivo.
- La ampliación evolutiva sólo se ejecuta expresamente en conexión propia. Mantener pruebas de migración y rollback en bases temporales; revisión visual, motores reales y concurrencia entre procesos deben verificarse por separado.

## Reglas añadidas por el ajuste 7.3.1

- Resolver una sola opción activa por nombre real de ruta Ziggy y recurso exacto; conservar estilos locales sin modificar botones globales.
- En selección múltiple, pendiente significa relación activa sin presentación activa en la revisión más reciente de alguna unidad comercial activa. No revivir revisiones anteriores ni ignorar otra presentación válida.
- Ordenar globalmente por unidad base ERP, código/nombre textual y relación antes de paginar; conservar ceros iniciales. Unidades no verificables permanecen desconocidas y separadas.
- Leer fuentes por bloques y mantener la organización ERP en el consumidor. No hacer joins cruzados ni escrituras ERP. Los temporales de ordenación son privados, por petición y eliminados al terminar o fallar; no publicar resultados parciales.
- Conservar selección entre filtros/páginas, advertir seleccionados no visibles y permitir retirarlos. Compartir unidad no acredita equivalencia comercial. Mantener validación final del lote y protección contra duplicados.
- Refrescar totales y pendientes después de crear conservando filtros, selección y resumen, sin repetir escrituras. No crear migraciones, alterar propuestas existentes ni iniciar Fase 7.4.


## Reglas añadidas por el ajuste 7.3.2

- Se autoriza generación de propuestas por política, selección explícita congelada y procesamiento durable por producto; no órdenes oficiales, compras automáticas ni Fase 7.4.
- Aplicar la política seleccionada como instantánea: ventana real, cobertura mínima, plazo y días de protección. No cambiar configuración global para elegir política o estacionalidad.
- Preferir pronóstico utilizable compatible; heurística diaria FAC sólo con autorización y cobertura completa suficiente. No dividir pronósticos mensuales ni convertir huecos en cero. Separar historia visual y ventanas descriptivas del intervalo de demanda.
- No declarar descenso por un solo mes; mantener tendencia descriptiva, escenarios conservadores explícitos y confianza desconocida. Sobrecobertura sólo advierte: nunca excluye automáticamente.
- ProductCostSource pertenece al consumidor. Columna, moneda y semántica por unidad base requieren verificación; dolarrep no acredita por su nombre USD ni unidad. Mantener cantidades y costos como cadenas exactas, separar monedas y costos faltantes.
- Congelar selección en servidor con UUID, versión y total confirmado; procesar y guardar bloques reanudables. No permitir editar ni aprobar una propuesta mientras se construye. Cancelar una construcción parcial conserva su auditoría y líneas, sin borrarlas.
- Conservar resultados originales, decisiones, exclusiones y referencia individual de generación en cada línea. Revalidar evidencia antes de publicar y rechazar cambios, sin alterar históricos.
- Usar semanas lunes-domingo recortadas al mes, sin duplicar días. Comparar sólo períodos completos y comparables; conservar NULL, parcialidad y origen de caché. Consultar historia bajo demanda con permisos y límites.
- Auditar actores autenticados de políticas y propuestas; resolver nombres exclusivamente en el consumidor. No recibir actores desde Vue.
- No migrar bases reales, escribir ERP ni certificar motores reales a partir de SQLite o SELECT. Mantener documentados límites de instantánea distribuida, concurrencia y revisión visual.


## Reglas añadidas por el ajuste 7.3.2.1

- El horizonte automático es plazo individual más cobertura de la política; no enviar extended_days cuando la sustitución esté vacía ni reemplazar un plazo desconocido por cero.
- Reutilizar demanda acumulada exacta de protección. Seguridad y pendientes desconocidos impiden alertas favorables; asumir ausencia no equivale a certificarla. Contar sólo entradas certificadas con fecha aplicable al intervalo semiabierto, una sola vez.
- Exigir suficiencia de ambos escenarios cuando se aporta uno conservador. Mostrar revisión ante discrepancias; no excluir automáticamente ni garantizar ausencia de agotamientos o mejorar la calidad exploratoria.
- No extrapolar silenciosamente demanda para un horizonte manual distinto. Conservar NULL si falta respaldo para ese intervalo. Mantener intactos los resultados históricos y distinguirlos de evaluaciones actuales; no crear migraciones ni iniciar 7.4 por esta corrección.


## Reglas añadidas por el ajuste 7.3.2.2

- Como excepción explícita a la restricción de cobertura de 7.3.2.1, permitir cero cuando el lote de entradas sea desconocido y assume_no_incoming esté autorizado. Identificarlo como explicit_zero_incoming_assumption, con diagnóstico exploratorio y advertencias.
- Priorizar siempre entradas certificadas con calendario válido. No sustituir compras positivas sin calendario por cero, alterar PurchaseSource, falsificar certified_absent ni persistir la hipótesis como verified_incoming.
- Conservar cantidades certificadas originales, entradas consideradas y procedencia separadas. Mantener controles de demanda, seguridad, horizonte y escenarios contradictorios; no excluir automáticamente ni garantizar ausencia de agotamientos.
- Mostrar hipótesis y calidad en el detalle; no modificar generaciones históricas ni inventarles los campos de evaluación nuevos. No crear migraciones ni iniciar Fase 7.4 por esta corrección.

## Reglas añadidas por la Fase 7.4.1

- Se autoriza el dashboard analítico y operativo de solo lectura; no calendario, alertas avanzadas ni Fase 7.4.2.
- Leer tablas propias mediante modelos y PersistenceConnection. No reconstruir caché, recalcular pronósticos ni consultar históricos ERP al abrir.
- Separar cabeceras, líneas y productos únicos. Aplicar ámbito en servidor y no sumar decisiones de distintas generaciones como propuesta vigente.
- Reutilizar el calendario de SalesHistoryBreakdownService; conservar fechas, unidades, parcialidad, desconocidos y ceros verificados. Un resumen mensual no acredita semanas.
- Recuperar calidad por vínculos explícitos de auditoría y no ensamblar generaciones por similitud. Sin evidencia, mostrar calidad desconocida; nunca elevar una simulación por aprobación humana.
- Mantener permisos directos de consulta, paginación acotada y carga analítica por producto bajo demanda. No escribir ni reanudar generaciones desde consultas GET.
- Conservar PolicyGeneration.vue, CoverageCalculation.vue y los cambios de 7.3.2.3. No modificar migraciones ni verificar motores reales con SQLite.

## Publicación comercial por lotes (7.4.2-A.2)

- Para días y unidades de una publicación nueva, preparar cada fila con las reglas del modelo, insertar por lotes en la conexión propia y verificar fechas, claves reales y cantidades antes del callback y del commit. Las publicaciones confirmadas son inmutables.
- Al recuperar un intento, respetar la reserva UTC y conservar la etapa interrumpida. Comprobar token y versión antes de fijar la publicación o confirmar el resultado.
