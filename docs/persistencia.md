# Diseño de persistencia — Fase 2

## Decisiones previas al esquema

### Conexión independiente — Ajuste 2.1

Todas las entidades resuelven su destino mediante PersistenceConnection. `replenishment.connection` lee `REPLENISHMENT_DB_CONNECTION`; si falta o está vacío utiliza el nombre de `database.default`. Se valida que exista en `database.connections`. El nombre Laravel y el driver son conceptos distintos: los ejemplos MySQL y SQL Server están en [integración](integracion.md). No se crean conexiones ni se consulta la configuración de adaptadores ERP.

Los modelos y relaciones no admiten redirigirse a otro destino mediante `on` o `setConnection`. Las futuras transacciones deben usar `app(Asisnet\Replenishment\Services\PersistenceConnection::class)->transaction($callback)`; el callback recibe la conexión propia. No usar `DB::transaction` sobre la predeterminada ni cambiar la configuración mientras una operación está en curso. Para operaciones internas que necesiten el constructor de consultas, obtener primero `connection()` del mismo servicio.

PackageMigrator utiliza su propio resolver y el registro `replenishment_migrations` en el mismo destino que el DDL. Su resolver no modifica la conexión predeterminada global, incluidos los callbacks de eventos. El mecanismo dedicado es `replenishment:migrate` y su opción `--rollback`; las rutas internas no se registran en el migrador general y una ejecución directa sin el contexto propio se rechaza. Desde 7.2.1 existe una modalidad estándar optativa con envoltorios y comprobación de conexión/historial; véase [migraciones](migraciones.md).

Las doce tablas de dominio no cambian. El registro dedicado es infraestructura adicional necesaria para aislar lotes y reversiones de otras migraciones. Los esquemas previos sin ese registro requieren conciliación manual verificada; no se adoptan automáticamente. Este mecanismo no ofrece transacciones distribuidas con conexiones ERP.

Verificación del Ajuste 2.1: 56 pruebas focalizadas y 304 aserciones satisfactorias en Laravel 12/PHP 8.2, incluidas 11 pruebas nuevas con dos SQLite en memoria. Se cubrieron destino predeterminado, nombre inexistente, registro dedicado, reversión, relaciones, transacciones, variable de entorno y rechazo de la ruta manual desde migrate general. Se comprobó además la sintaxis de los archivos modificados con PHP 8.1. No se repitió la matriz completa ni se ejecutaron migraciones sobre consult-web; MySQL y SQL Server reales siguen pendientes.

Se conservan contratos, DTO y registro de adaptadores. Las doce tablas propias usan el prefijo `replenishment_`. Los identificadores externos son cadenas opacas de hasta 100 caracteres; no tienen relaciones hacia el ERP ni hacia usuarios. El alcance inicial es una fuente de datos por conexión del paquete: producto-proveedor es único por ambos identificadores, independientemente de la ubicación. La segregación entre empresas requiere conexiones separadas; no se inventa un identificador de empresa.

Las configuraciones comerciales son PurchaseUnit, ProductSupplier, SupplierPresentation y Policy. Las observaciones ProductMetric, SupplierMetric, SeasonalPattern, DemandForecast y ReplenishmentResult se conservan como históricos anexables. ReplenishmentRun registra su ejecución; PurchaseProposal y PurchaseProposalItem son documentos editables. No se implementa ningún cálculo ni flujo de aprobación.

Las claves foráneas internas utilizan NO ACTION: no hay borrados en cascada ni nulificación de vínculos históricos. Los nombres explícitos de índices y restricciones son cortos. Los índices compuestos de identificadores externos se mantienen por debajo de 900 bytes incluso con nvarchar; ninguna clave indexa textos extensos o JSON.

## Presentaciones y trazabilidad

Una relación producto-proveedor admite varias unidades comerciales y varias revisiones de cada unidad. La combinación relación, unidad y revisión es única. La revisión empieza en 1; crear explícitamente otra revisión permite cambiar una conversión sin sobrescribir la anterior. No se calcula automáticamente la siguiente revisión. Después de guardar una presentación, sólo puede cambiar su indicador active mediante el modelo. Los identificadores de una relación producto-proveedor tampoco se cambian después de crearla.

Los resultados y las líneas de propuesta capturan una instantánea de la presentación seleccionada a partir de las tablas propias: identidad, revisión, código y nombre de unidad, conversión, mínimo y múltiplo. Esta copia de configuración conocida no deduce equivalencias. Cambiar el nombre de una unidad posteriormente no cambia la instantánea histórica. Si no se selecciona presentación, la instantánea queda NULL y no se inventa ninguna. Las cantidades sugeridas no se recalculan aquí.

## Cantidades, desconocidos y valores iniciales

Cantidades, factores y costos usan decimal(24,6); probabilidades y niveles normalizados usan decimal(9,6), entre 0 y 1. Se aceptan cadenas decimales o enteros exactos; float, notación exponencial, desbordamientos y fracciones no representables se rechazan. Las cantidades monetarias no se convierten entre monedas. estimated_unit_cost representa costo por unidad base, no por paquete.

SQLite no garantiza precisión decimal con afinidad NUMERIC. En SQLite los mismos campos se crean como TEXT y el cast los normaliza exactamente a su escala; SQL Server y los demás motores usan DECIMAL. Esto permite pruebas de transporte exacto en SQLite, pero no certifica semántica de agregados SQL entre motores: cualquier futura aritmética deberá probarse en su motor de destino.

La información no calculada o desconocida es NULL. No hay ceros predeterminados en métricas. Los parámetros comerciales de políticas empiezan en NULL en la configuración y la política está inactiva por defecto. Una política activa exige parámetros explícitos válidos. No se eligen ventanas, niveles de servicio ni plazos comerciales del cliente. Los estados iniciales son pending para ejecuciones, draft para propuestas y pending para resultados.

Las ubicaciones pueden ser NULL cuando el dato no tiene alcance local explícito; NULL nunca significa sumar todas las ubicaciones. No hay unicidad que reemplace históricos por producto o período: distintas fechas de cálculo, algoritmos y ejecuciones se conservan. Las ventanas son semiabiertas [inicio, fin), y los instantes se suministran en UTC.

## Integridad y límites

La base aplica claves primarias, relaciones internas, NOT NULL y unicidad. Los modelos validan decimales, estados, intervalos, rangos, coherencia de producto/proveedor y campos requeridos al activar políticas. Las escrituras masivas del query builder y el SQL directo omiten los eventos Eloquent; se reservan a migraciones o mantenimiento controlado y no son una API de escritura de dominio. La inmutabilidad histórica también se protege en los eventos de los modelos, sin afirmar que equivalga a permisos o triggers de base de datos.

No se consultará una base SQL Server del consumidor. La compatibilidad SQL Server 2017 tendrá revisión de SQL generado, tipos y límites; si no hay una instancia aislada explícitamente disponible, la ejecución real quedará pendiente.
## Tablas y modelos

| Tabla | Modelo | Propósito y relaciones |
| --- | --- | --- |
| `replenishment_purchase_units` | `PurchaseUnit` | Catálogo; código único y múltiples presentaciones |
| `replenishment_product_suppliers` | `ProductSupplier` | Relación única producto-proveedor; presentaciones y métricas propias |
| `replenishment_supplier_presentations` | `SupplierPresentation` | Pertenece a relación comercial y unidad; revisión única |
| `replenishment_policies` | `Policy` | Parámetros vigentes; todavía sin asignación ni herencia |
| `replenishment_product_metrics` | `ProductMetric` | Histórico por producto, ubicación, ventana y algoritmo |
| `replenishment_supplier_metrics` | `SupplierMetric` | Histórico asociado a relación comercial |
| `replenishment_seasonal_patterns` | `SeasonalPattern` | Históricos mensuales o semanales; historical_years es un conteo |
| `replenishment_demand_forecasts` | `DemandForecast` | Históricos por período, generación y versión |
| `replenishment_runs` | `ReplenishmentRun` | Contexto de ejecución; resultados y propuestas relacionadas |
| `replenishment_results` | `ReplenishmentResult` | Pertenece a ejecución; presentación e instantánea opcionales |
| `replenishment_purchase_proposals` | `PurchaseProposal` | Cabecera con proveedor externo y ejecución interna opcional |
| `replenishment_purchase_proposal_items` | `PurchaseProposalItem` | Pertenece a cabecera; presentación e instantánea opcionales |

No hay modelos Product, Supplier, Location ni User internos que dupliquen los maestros del consumidor. Las presentaciones no añaden datos a los DTO de lectura de la Fase 1.

## Estados y categorías

| Campo | Valores persistidos |
| --- | --- |
| Estado de ejecución | `pending`, `running`, `completed`, `failed`, `cancelled` |
| Estado de resultado | `pending`, `recommended`, `not_required`, `insufficient_data`, `error` |
| Estado de propuesta | `draft`, `in_review`, `approved`, `rejected`, `cancelled` |
| Tipo de patrón | `monthly` (1–12), `weekly` (1–53) |
| Granularidad de pronóstico | `daily`, `weekly`, `monthly` |

Son cadenas validadas por los modelos; no enums SQL. La Fase 2 valida pertenencia al dominio, pero no ejecuta transiciones, aprueba documentos ni impone permisos de usuario. Los campos de revisión y aprobación se suministran explícitamente. Las clasificaciones de rotación, ABC y de período son etiquetas opcionales, sin algoritmo de clasificación ni catálogo impuesto.

El contexto de una ejecución —ventana, versión, parámetros y solicitante— es inmutable después de crearla; sus estados e instantes de inicio/finalización pueden actualizarse. Si una fase futura utiliza una política, debe copiar los parámetros efectivamente utilizados en `parameters_json`; cambiar una política no debe reinterpretar una ejecución anterior. No existe una selección automática de política en esta fase.

## Convenciones de los campos

- `product_id`, `supplier_id`, `location_id`, `requested_by`, `created_by` y `reviewed_by` son cadenas externas. Su comparación en SQL sigue la intercalación de la base; antes del despliegue se debe confirmar si el origen distingue mayúsculas y minúsculas.
- Ventas brutas y devoluciones en métricas son magnitudes separadas no negativas; ventas netas pueden ser negativas. Esto no modifica el convenio de `SaleData`, donde una devolución tiene signo negativo.
- Demanda, existencias, reservas, mercancía entrante y cantidades base se expresan en la unidad base del producto. `suggested_packages`, `approved_packages`, `minimum_packages` y `package_multiple` se expresan en presentaciones; pueden ser fraccionarias si se suministran así.
- Conversión y múltiplo deben ser positivos; el mínimo es no negativo. No hay redondeo, conversión automática entre paquetes y unidades base ni exigencia de que las cantidades sugeridas satisfagan múltiplos: esas operaciones pertenecen al futuro motor.
- Plazos, conteos de observaciones, órdenes y años históricos son enteros no negativos de 32 bits. Las ventanas de una política y el mínimo histórico/años estacionales, cuando se definen, son positivos; su plazo de entrega puede ser cero explícito.
- `service_level` es una proporción mayor que cero y menor o igual que uno. Calidad, confianza y cumplimiento se definen como proporciones entre cero y uno; un porcentaje debe normalizarse explícitamente antes de persistir.
- `parameters_json` y `presentation_snapshot` usan TEXT, que Laravel compila a nvarchar(max) en SQL Server. El cast serializa estructuras JSON, no un tipo JSON nativo ni funciones JSON del motor.
- Las fechas de análisis y pronóstico son ventanas [inicio, fin); las fechas de pedido/recepción son fechas de calendario. Los instantes recibidos con zona horaria se normalizan a UTC y conservan seis decimales de segundo; cadenas sin zona se interpretan en UTC.
- Una política incompleta puede guardarse inactiva. Para activarla deben definirse los siete parámetros de `policy_defaults`; los ejemplos de pruebas no son valores comerciales recomendados.
- `is_preferred` es una marca, sin exigir un único proveedor preferido: todavía no hay una regla comercial de prioridad por ubicación.
- Las sugerencias y aprobaciones de una línea se conservan por separado. No se infiere un costo a partir de otra unidad ni se integra con órdenes oficiales.

Los errores de datos producen InvalidArgumentException, los intentos de alterar históricos o identidades fijas producen LogicException y las restricciones de la base producen QueryException. Una presentación o cabecera interna inexistente al capturar instantáneas produce ModelNotFoundException. Las instancias deben persistirse mediante Eloquent con sus eventos habilitados.

## SQL Server 2017

Se revisan tipos bigint/int, bit, nvarchar, nvarchar(max), decimal, date y datetime2(6), disponibles en el motor objetivo. No se usa timestamp de SQL Server como fecha ni se requiere JSON nativo. Las claves externas opacas tienen longitud 100; las claves compuestas diseñadas quedan por debajo del límite conservador de 900 bytes. Los nombres explícitos de restricciones e índices se comprueban contra 128 caracteres.

La revisión de gramática ejecutada en las tres versiones de Laravel compila creación y reversión, sin abrir PDO. No valida permisos, intercalación, comportamiento del controlador, transacciones DDL ni persistencia real de decimales en SQL Server. Antes del despliegue debe ejecutarse la misma suite de integridad adaptada a una base SQL Server 2017 temporal y vacía. MySQL y PostgreSQL tampoco se declaran verificados mediante estas pruebas.

## Evolución de ventas observadas (Fase 4.1)

Una migración adicional conserva las doce tablas anteriores y crea replenishment_daily_sales, una caché reconstruible con clave única por producto, ubicación y fecha. ProductMetric añade reversed_returns y observed_sales_summary nullable; las demás columnas mantienen su significado. Caché y métrica se publican atómicamente en PersistenceConnection. Consultar [persistencia, reconstrucción y reversión](demanda-observada.md). No se ejecutó esta evolución en bases reales.

## Ajuste de Fase 5.1

ObservedDemandStore rechaza ahora todo lote comercial incompleto dentro de la transacción, sin reemplazar días completos por NULL ni añadir una métrica parcial. SeasonalityStore reutiliza las tablas estacionales y de métricas: la auditoría JSON de la métrica enlaza identidades de patrones y conserva la evidencia del cálculo. No hay nuevas migraciones; todas las escrituras pasan por PersistenceConnection y los históricos anteriores permanecen inmutables. Véase [reglas de publicación](estacionalidad.md).

## Pronósticos de Fase 5.2

ForecastStore reutiliza replenishment_demand_forecasts y la auditoría JSON de ProductMetric, bajo la versión observed-forecast-1.0. Las claves forecast_audit y demand_forecast_ids distinguen pronósticos de ventas observadas y vinculan sus filas. La calidad está en la auditoría: un histórico persistido puede ser exploratorio. No se sobrescriben filas ni se inventan límites/confianza. La publicación completa es atómica mediante PersistenceConnection, sin migraciones adicionales. Véase [persistencia y evaluación](pronosticos.md).

## Métricas de proveedor por destino (Fase 6.1)

La migración 2026_11_09_000003 amplía exclusivamente replenishment_supplier_metrics con location_id y lead_time_evidence nullable e índice por relación/destino/fecha. Mantiene filas antiguas sin atribuirles ubicación. No modifica tablas ERP ni incorpora nuevas tablas. SupplierLeadTimeStore conserva los indicadores originales como primera recepción observada y guarda calidad, estadísticas certificadas y evidencia por separado en JSON. No consultar una métrica exploratoria como plazo certificado ni mezclar destinos NULL con destinos conocidos.

Las nuevas métricas se añaden mediante modelos dentro de PersistenceConnection; siguen siendo inmutables. Sólo el migrador propio aplica/revierte el lote. La reversión elimina atributos nuevos e índice, preservando filas y campos anteriores. Ver [reglas de evidencia y persistencia](plazos-proveedores.md). Evolución y reversión verificadas con SQLite aislado; no ejecutadas en bases reales ni certificadas para persistencia SQL Server mediante lecturas ERP.

## Ejecuciones de planificación (Fase 6.2)

Se reutilizan replenishment_runs y replenishment_results, admitiendo simulation en la validación del modelo; las columnas existentes son texto, por lo que no hay migración adicional. Publicación explícita y atómica mediante PersistenceConnection, con resultado inmutable, fotografía comercial y auditoría completa en parameters_json/explanation. Una presentación modificada exige repetir el cálculo antes de publicar. Las columnas decimales son resúmenes HALF_UP a seis posiciones; la auditoría conserva operaciones exactas. Una entrada supuesta no se registra como verified_incoming. Véase [trazabilidad y transacciones](reposicion.md).

## Administración y revisiones (Fase 7.1)

CommercialConfiguration reutiliza las tablas y restricciones existentes. Toda escritura usa modelos dentro de PersistenceConnection. Factor, mínimo o múltiplo diferentes añaden revisión, con bloqueo de la relación y unicidad en base; no se reescriben fotografías históricas. No se ofrece eliminación física. Se corrigió la comparación del cast ExactDecimal requerida por Eloquent al editar políticas, manteniendo la comparación previa de dos escalares. Sin migraciones nuevas ni reales. La validación MySQL aislada permanece pendiente por falta de una base de pruebas identificada; SQLite y lecturas SQL Server no la sustituyen. Véase [verificación y límites](configuracion-comercial.md).

## Control de importaciones (7.2.1)

La única migración evolutiva nueva añade replenishment_supplier_imports, separada de las tablas comerciales. SupplierImport utiliza la conexión propia y conserva UUID, actor, opciones, estado, versión, cursor, conteos, vista previa y detalle del último bloque. SupplierImportService confirma relaciones nuevas y avance en una misma transacción. Una reversión de esta ampliación pierde el control de reanudación, por lo que no debe aplicarse con operaciones en curso. No se ejecutó en el consumidor. Véase [procedimiento seguro](migraciones.md).

## Evidencia de propuestas de Fase 7.3

Se añaden `version`, `creation_key` y `derived_from_id` a cabeceras; `source_result_id`, `evidence`, `decision` y `removed_at` a líneas; y `replenishment_proposal_events` para actor, acción, UUID, versión y estados antes/después. Los nuevos textos JSON conservan cantidades exactas y no cambian los decimales heredados. Los eventos y resultados son inmutables. Las líneas retiradas permanecen consultables en auditoría. Detalles y rollback: [Propuestas](propuestas.md).

El costo estimado continúa siendo por unidad base; esta fase mantiene NULL al no existir una fuente vigente acreditada. No se computan totales financieros. Una propuesta aprobada no se registra como mercancía entrante ni ejecuta operaciones ERP.


## Ajuste 7.3.2 — Generación por política

Se añade generación durable por bloques, historia anual/mensual/semanal, decisiones auditadas y costo opcional con semántica explícita. Consulte [la documentación](generacion-politica-732.md). La migración 000006 requiere ejecución manual posterior; no se migró consult-web. La verificación de costos, motores reales y concurrencia entre procesos permanece diferenciada de SQLite y de consultas SELECT.
