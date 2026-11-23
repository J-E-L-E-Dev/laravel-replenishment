# Arquitectura de la fundación

## Dependencias

El núcleo de contratos y DTO sólo utiliza PHP. El proveedor, el registro y el comando dependen de los contratos, soporte y consola de Illuminate. La Fase 2 declara además Illuminate Database para Eloquent y el esquema. Testbench y PHPUnit son dependencias exclusivas de desarrollo.

Flujo de lectura: aplicación consumidora → adaptador propio → contrato del paquete → DTO → cálculo de disponibilidad. La persistencia propia permanece separada mediante modelos Eloquent, sin escribir datos del ERP ni implementar el motor.

## Estructura

| Directorio | Responsabilidad |
| --- | --- |
| `src/Contracts` | Seis interfaces de lectura del origen |
| `src/DTO` | Datos normalizados e inmutables |
| `src/Services` | Adaptadores configurados, persistencia aislada y consulta/cálculo de disponibilidad |
| `src/Commands` | Diagnóstico sin acceso a datos |
| `src/Models` | Doce entidades persistentes y su integridad común |
| `src/Casts` | Transporte decimal exacto |
| `src/Database/Migrations` | Implementación del esquema propio |
| `config` | Clases de adaptadores; valores iniciales null |
| `database/migrations` | Entrada de la migración exclusiva de replenishment:migrate |
| `tests` | Pruebas de integración aislada y transporte de datos |
| `docs` | Arquitectura, integración y evidencia |

## Contratos iniciales

| Contrato | Operación | Semántica |
| --- | --- | --- |
| `ProductSource` | `find` | Producto y unidad base de inventario |
| `InventorySource` | `current` | Existencia física observada por producto y ubicación |
| `InventoryMovementSource` | `between` | Movimientos históricos ordenados, cantidades verificables y limitaciones de calidad |
| `ReservationSource` | `activeFor` | Líneas de reserva activas con saldo pendiente |
| `SaleSource` | `between` | Ventas confirmadas y devoluciones dentro de [inicio, fin) |
| `PurchaseSource` | `pendingFor` | Pendiente completo verificado; excepción explícita si no puede certificarse |
| `PurchaseHistorySource` | `find`, `between`, `reconcile`, `receiptsBetween` | Órdenes, recepciones candidatas y conciliación detallada con incertidumbre |
| `SupplierSource` | `find` | Identidad y nombre del proveedor |

Los identificadores son cadenas opacas y conservan ceros iniciales. La ubicación es explícita: no se supone un almacén global. Los resultados iterables pueden ser diferidos, con orden determinista obligatorio para movimientos históricos; el adaptador debe garantizar identidades únicas de líneas. Una colección vacía indica ausencia confirmada de registros, nunca un fallo del origen.

## Cantidades y tiempos

Las cantidades operativas son cadenas decimales en la unidad base del producto; los movimientos históricos conservan además la cantidad y unidad originales y dejan la equivalencia base en NULL cuando no se verifica, con punto decimal, sin separadores de miles ni notación exponencial. No se usa float. La Fase 3.1 agrega exclusivamente compromisos actuales mediante aritmética decimal exacta. Inventario físico puede ser negativo si así lo registra el origen. Las reservas son no negativas y pueden indicar cantidad desconocida mediante NULL; las compras pendientes son positivas. Ventas positivas representan salidas; devoluciones negativas representan entradas. Se excluyen borradores; las anulaciones contabilizadas mediante contramovimientos firmados se conservan.

Los DTO son transporte, no validadores: el adaptador debe garantizar estas precondiciones antes de construirlos. Ante datos inválidos, conversiones desconocidas o fallos de acceso, la proyección comercial requiere una excepción RuntimeException; el contrato de movimientos puede conservar la observación con cantidades desconocidas y causas explícitas. No inventar información. El intervalo de ventas debe ser creciente o lanzar InvalidArgumentException. Los instantes son DateTimeImmutable con zona horaria y se comparan como instantes absolutos. No se promete una instantánea transaccional entre contratos.

Una observación de inventario desconocida se devuelve como null, no como existencia cero. La fecha esperada de compra puede ser null. La existencia física nunca descuenta reservas dentro del adaptador de inventario.

## Presentaciones comerciales

La unidad base no representa una presentación comercial. Los contratos y DTO originales no se amplían con empaques inferidos. La Fase 2 permite almacenar presentaciones y conversiones explícitas en tablas propias, según [el diseño de persistencia](persistencia.md). En particular, `C02091.unidad` no constituye evidencia de presentación comercial. Si una compra usa otra unidad, sólo puede normalizarse mediante una conversión explícita y verificable del origen; de lo contrario debe fallar de manera informativa.

## Registro y efectos

El proveedor mezcla configuración, registra AdapterRegistry como singleton y habilita publicación y diagnóstico en consola. No registra automáticamente implementaciones de los ocho contratos. El registro construye sólo el adaptador solicitado y valida tanto su clase como el resultado del contenedor. Las dependencias y excepciones de construcción pertenecen al consumidor.

El diagnóstico únicamente muestra configuración; un código de éxito no certifica conectividad. El proveedor registra el comando explícito de migración, sin incorporar rutas al migrador general. PersistenceConnection resuelve modelos, esquema y transacciones propias; PackageMigrator mantiene su registro dedicado en esa misma conexión sin modificar el destino global de Laravel. Los modelos validan escrituras individuales mediante eventos Eloquent. No hay consultas al ERP, tareas programadas ni rutas HTTP.



## Motor observado (Fase 4.1)

SaleData amplía su constructor con kind y baseUnit opcionales; los adaptadores deben clasificarlos explícitamente para generar desgloses. ObservedDemandCalculator consume SaleSource sin conocer el ERP, y ObservedDemandStore separa reconstrucción de caché e históricos inmutables. Consultar [fórmulas, calidad y calendario](demanda-observada.md). Las limitaciones de inventario no invalidan por sí solas ventas normalizadas.

## Evidencia física histórica (Fase 4.2)

StockHistoryAnalyzer reutiliza el contrato de movimientos y el inspector consecutivo. Separa observaciones, intervalos y días acreditados; StockHistoryQuery coordina fuentes y contraste comercial opcional. No hay nueva persistencia. Véase [inventario histórico](inventario-historico.md).

## Estacionalidad mensual (Fase 5.1)

SeasonalityAnalyzer recibe ObservedDemandData y opciones explícitas; SeasonalityQuery coordina origen/caché y SeasonalityStore publica históricos propios con auditoría vinculada. La normalización anual separa escala y concentración mensual; no se pronostica. Véase [metodología](estacionalidad.md).

## Pronósticos observables (Fase 5.2)

DemandForecastEngine coordina ForecastTraining, ForecastBacktester y ForecastEvaluator. ForecastModelInterface admite métodos puros; StatisticalForecastModel implementa alternativas simples con parámetros auditados. ForecastQuery reutiliza las fuentes comerciales/caché y ForecastStore conserva pronósticos inmutables con auditoría relacionada. Se separan selección temporal y validación reservada. Véase [metodología](pronosticos.md).

## Plazos de proveedores (Fase 6.1)

SupplierLeadTimeAnalyzer consume PurchaseHistorySource y conserva sus límites de conciliación. SupplierLeadTimeResolver separa manual activo, historial certificado utilizable, política explícita y desconocido. SupplierLeadTimeQuery coordina lecturas sin exigir tablas propias; admite cobertura externa verificada sin que el adaptador Orbis la genere. SupplierLeadTimeStore publica métricas históricas inmutables por destino mediante PersistenceConnection. Los DTO conservan evidencia y auditoría sin referencias al ERP. Véase [evidencia, límites y precedencia](plazos-proveedores.md).

## Planificación de Fase 6.2

ReplenishmentQuery coordina consultas ya aprobadas. ReplenishmentCalculator suma pendientes certificados y demanda de meses completos, y redondea presentaciones con Brick Math. ReplenishmentPlanner combina disponibilidad, evidencia y escenarios sin acceder al ERP; ReplenishmentStore añade ejecución/resultado transaccionalmente. ReplenishmentOptions y ReplenishmentPlanData conservan parámetros y auditoría. SafetyStockEvidenceData admite sólo calibración externa previamente verificada; Orbis y la CLI no la generan. Véase [alcance, fórmulas y estados](reposicion.md).

## Administración comercial (Fase 7.1)

CommercialConfiguration centraliza altas, consultas, validación y ediciones de unidades, relaciones, presentaciones y políticas. Las restricciones reutilizan los modelos; conversiones nuevas generan revisiones dentro de PersistenceConnection. Los contratos de maestros permanecen en el consumidor. Controlador, FormRequest, rutas y autorización HTTP son adaptadores de consult-web. Véase [arquitectura, versionado y conflicto de Gate](configuracion-comercial.md).

## Interfaz de administración y simulación (Fase 7.2)

Los controladores web, capacidades, middleware de sesión y componentes Inertia/Vue pertenecen a consult-web. Invocan los servicios existentes sin HTTP interno. El paquete conserva las reglas comerciales y la conexión PersistenceConnection; el frontend sólo captura cadenas, navega y presenta resultados. La simulación no llama a ReplenishmentStore ni modifica históricos.

Las consultas auxiliares JSON son acotadas y los maestros se resuelven mediante sus contratos por código exacto. El control de permisos es directo mediante Spatie, independiente del Gate personal del consumidor. Las revisiones obsoletas no pueden editarse desde la interfaz. No se añadieron dependencias ni migraciones para esta fase.

## Ajuste 7.2.1

PublishedMigration adapta definiciones existentes al migrador estándar y valida su repositorio real antes de habilitar el mismo esquema. No sustituye el migrador global. SupplierImportService coordina el contrato SupplierProductSource, los maestros y la administración comercial mediante transacciones propias. El consumidor conserva consultas ERP, filtrado de nombres, unidades base, transporte web y procesamiento de lotes; Vue no incorpora cálculos comerciales.

## Ajuste 7.2.2

BulkPresentationCreator coordina una transacción de configuración común, omite presentaciones existentes y deja revisiones individuales al servicio aprobado. La validación de unidad base proviene de ProductSource, no de unidades comerciales ni conversiones inferidas.

CommercialBrowser del consumidor separa filtros propios de descripciones externas y calcula totales completos con memoria por bloques. La materialización efímera de IDs es un recurso de la petición web, no persistencia del paquete. PurplePaginationCurrent, máscaras de códigos y formato textual son exclusivamente presentación. No hay cambios en persistencia, algoritmos ni dependencias.

## Presentación guiada 7.2.3

La capa del consumidor formatea cantidades mediante cadenas, controla solicitudes obsoletas y presenta explicaciones con operandos devueltos por ReplenishmentQuery. La preparación visual no sustituye validaciones del dominio. No cambia los cálculos, las fuentes ni la persistencia: los resultados anteriores conservan el escenario enviado y se marcan desactualizados al editar. Las políticas requieren vinculación explícita para utilizarse.

## Flujo comercial de propuestas

Fase 7.3 añade `PurchaseProposalService` para comandos transaccionales y `PurchaseProposalQuery` para consultas acotadas. Reutiliza DTO `ReplenishmentPlanData`, cálculo y publicación existentes. `ProposalEvent` registra decisiones inmutables; cada línea calculada tiene su resultado propio. El consumidor aporta autenticación, capacidades, evidencia efímera del simulador y transporte. La aprobación cambia autorización comercial, no calidad estadística ni inventario. Véase [Propuestas](propuestas.md).

## Organización del selector en 7.3.1

El consumidor aplica vigencia en persistencia propia y resuelve unidades/descripciones ERP por bloques. Ordena mediante mezcla de archivos efímeros antes de paginar, sin joins cruzados, caché permanente ni migraciones. Los archivos se eliminan al finalizar o fallar; no se presenta un total parcial. El núcleo, los cálculos y la aprobación de propuestas no cambian. No hay instantánea distribuida: cambios concurrentes pueden alterar páginas entre consultas.


## Ajuste 7.3.2 — Generación por política

Se añade generación durable por bloques, historia anual/mensual/semanal, decisiones auditadas y costo opcional con semántica explícita. Consulte [la documentación](generacion-politica-732.md). La migración 000006 requiere ejecución manual posterior; no se migró consult-web. La verificación de costos, motores reales y concurrencia entre procesos permanece diferenciada de SQLite y de consultas SELECT.

## Fase 7.4.1 — Dashboard de consulta

Se incorporan consultas operativas y analíticas de solo lectura, con filtros, paginación y versiones vinculadas. El consumidor presenta las gráficas y conserva autenticación y navegación. Véase [arquitectura, evidencia y límites del dashboard](dashboard-741.md). No se inicia 7.4.2 ni se añaden migraciones.

## Historia comercial en unidades originales

`CommercialHistorySource` devuelve una ventana observable y movimientos individuales. `SaleSource` alimenta la serie heredada en unidades comparables. Con la fuente opcional configurada, la etapa C materializa ejecuciones inmutables con cobertura diaria y grupos por unidad. Los motores reciben la proyección homogénea de una sola ejecución. Véase [arquitectura y conciliaciones](ajuste-733.md).
