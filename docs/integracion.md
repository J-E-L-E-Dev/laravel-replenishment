# Integración local y distribución

## Consumidor inicial

`consult-web` utiliza PHP 8.2.28, Laravel 10.48.18 y Composer 2.8.11 en el entorno inspeccionado. Su requisito PHP es ^8.1 y el de Laravel es ^10.10. Conserva su configuración de autoload optimizado y descubrimiento automático, sin exclusiones del paquete.

Añadir al composer.json del consumidor:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../../mis-paquetes/laravel-replenishment",
            "options": {
                "symlink": true,
                "versions": {
                    "asisnet/laravel-replenishment": "dev-main"
                }
            }
        }
    ],
    "require": {
        "asisnet/laravel-replenishment": "dev-main"
    }
}
```

El fragmento se combina con el manifiesto existente. La versión explícita permite resolver el paquete antes de su primer commit, sin cambiar la estabilidad global del consumidor.

Desde el consumidor:

```sh
composer update asisnet/laravel-replenishment --no-scripts
php artisan package:discover --ansi
php artisan replenishment:status
```

Se omiten los scripts de Composer para evitar la publicación global de recursos que ejecuta actualmente post-update-cmd. El descubrimiento se ejecuta explícitamente. No actualizar Laravel ni otras dependencias.

En Windows, Composer utiliza una unión de directorio (Junction). Este mecanismo fue aceptado al aprobar la Fase 1 y debe conservarse. No es una copia del paquete y los cambios son visibles inmediatamente en el consumidor.

## Adaptadores futuros

Implementar cada contrato en el namespace de la aplicación y registrar su clase en `replenishment.adapters`. Las dependencias del adaptador se resuelven por el contenedor de Laravel. No colocar adaptadores de OrbisNET dentro del paquete.

Obtener el registro mediante `app(Asisnet\Replenishment\Services\AdapterRegistry::class)` y solicitar `resolve('products')`, por ejemplo. Sin configuración válida se lanza LogicException. El arranque de la aplicación no requiere adaptadores.

No se publican configuraciones ni se crean implementaciones comerciales en el consumidor durante esta fase.

Consultar [la verificación de la fundación](verificacion.md) para conocer la generación estándar de autoload aplicada en este entorno. Para actualizar únicamente los metadatos del paquete sin repetir el escaneo optimizado del SDK de AWS, se utilizó `composer update asisnet/laravel-replenishment --no-autoloader --no-scripts`. El autoload PSR-4 ya registrado encuentra las nuevas clases; no se actualizan otras dependencias.

## Persistencia y migraciones aisladas — Ajuste 2.1

`replenishment.connection` obtiene su valor de `REPLENISHMENT_DB_CONNECTION`. El valor identifica una entrada existente en `database.connections`; no es una URL, una base física ni necesariamente un driver. Si no se define o queda vacío, se usa `database.default`. Un nombre inexistente produce un error en español antes de ejecutar DDL o consultas de modelos.

El consumidor define sus conexiones; el paquete no las crea ni toma credenciales de adaptadores. Por ejemplo, agregar una de estas entradas a `connections` en `config/database.php`, conservando las existentes:

```php
// Nombre Laravel: replenishment_mysql. Driver: mysql.
'replenishment_mysql' => [
    'driver' => 'mysql',
    'host' => env('REPLENISHMENT_MYSQL_HOST', '127.0.0.1'),
    'port' => env('REPLENISHMENT_MYSQL_PORT', '3306'),
    'database' => env('REPLENISHMENT_MYSQL_DATABASE'),
    'username' => env('REPLENISHMENT_MYSQL_USERNAME'),
    'password' => env('REPLENISHMENT_MYSQL_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
],

// Nombre Laravel: replenishment_sqlserver. Driver: sqlsrv.
'replenishment_sqlserver' => [
    'driver' => 'sqlsrv',
    'host' => env('REPLENISHMENT_SQLSERVER_HOST', '127.0.0.1'),
    'port' => env('REPLENISHMENT_SQLSERVER_PORT', '1433'),
    'database' => env('REPLENISHMENT_SQLSERVER_DATABASE'),
    'username' => env('REPLENISHMENT_SQLSERVER_USERNAME'),
    'password' => env('REPLENISHMENT_SQLSERVER_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
],
```

Seleccionar uno de los nombres, por ejemplo:

```dotenv
REPLENISHMENT_DB_CONNECTION=replenishment_mysql
```

Para SQL Server sería `REPLENISHMENT_DB_CONNECTION=replenishment_sqlserver`, no `sqlsrv` salvo que ése sea realmente el nombre registrado. Un adaptador OrbisNET puede usar otra conexión, por ejemplo `erp_sqlserver`, sin que el paquete la consulte o modifique. Ajustar las opciones de transporte, certificados e intercalación conforme al entorno del consumidor; estos ejemplos no certifican una conexión real.

Si `config/replenishment.php` ya fue publicado con `'connection' => null`, actualizar esa línea a `'connection' => env('REPLENISHMENT_DB_CONNECTION')`; el archivo publicado prevalece sobre el valor del paquete. Tras cambiar configuración cacheada, regenerar la caché siguiendo el procedimiento habitual del consumidor. Esta corrección no publica ni modifica automáticamente su configuración.

### Migración explícita

En una base temporal dedicada y después de revisar la configuración, ejecutar:

```sh
php artisan replenishment:migrate
php artisan replenishment:migrate --rollback
```

El primer comando aplica únicamente migraciones pendientes del paquete; el segundo revierte su último lote. Ambos utilizan el mismo nombre para tablas, registro y transacciones de DDL. No aceptan `--database` ni `--path`: el destino proviene exclusivamente de la configuración. La protección habitual de Artisan en producción admite `--force` cuando el operador lo solicita explícitamente.

El registro propio es `replenishment_migrations`, una tabla de infraestructura con el formato estándar de Laravel. Se conserva al revertir, pero se eliminan las entradas revertidas. Este registro separado evita que un rollback general revierta el paquete, incluso cuando ambos usan la misma conexión. Las doce tablas de dominio y sus campos no cambian.

El proveedor no registra la ruta en `migrate`. Los comandos generales `migrate` y `migrate:rollback` no ejecutan estas migraciones; utilizar manualmente su ruta también falla con una indicación del comando correcto antes de ejecutar DDL propio. No se reemplaza el migrador global ni se altera `database.default` durante las operaciones explícitas.

### Instalaciones anteriores

Si ya existen tablas de la Fase 2 sin `replenishment_migrations`, el comando se detiene de forma controlada. No copia registros, mueve tablas, adopta esquemas ni elimina datos automáticamente. Antes de operar, respaldar y verificar las doce tablas en el destino correcto, identificar dónde quedó registrada la migración anterior y conciliar manualmente su entrada con el registro dedicado. No marcar una migración como ejecutada sin comprobar que su esquema completo realmente exista. Si las tablas están en otra base, su traslado requiere un trabajo explícito separado.

No se ejecutaron migraciones ni conciliaciones sobre consult-web. Las pruebas del ajuste utilizan dos SQLite en memoria independientes. La ejecución real en MySQL o SQL Server sigue pendiente de un entorno aislado autorizado.

## Publicación posterior

El repositorio path pertenece exclusivamente al consumidor. Antes de publicar, confirmar nombre definitivo, licencia y versión; etiquetar el repositorio y registrarlo en el servidor Composer elegido. En instalaciones distribuidas se eliminará el repositorio path y se sustituirá dev-main por la restricción publicada. Nunca copiar manualmente el paquete a vendor.

## Históricos (Fase 3.2A)

Configurar adapters.movements con InventoryMovementSource y adapters.sales con SaleSource en el consumidor. Consultar [semántica, flujo y calidad](historicos.md). La lectura histórica utiliza exclusivamente la conexión del adaptador, sin migraciones ni cambios de persistencia. Completar cada iterable antes de presentar totales; una excepción invalida acumulaciones parciales.

## Compras y proveedores (Fase 3.2B)

Configurar adapters.purchase_history con PurchaseHistorySource; suppliers mantiene SupplierSource. La proyección purchases conserva PurchaseSource y debe fallar si no puede certificar el pendiente completo. Consultar [conciliación y calidad](compras.md). Las diferencias matemáticas nunca sustituyen pendientes comerciales desconocidos. La persistencia propia y sus doce tablas no cambian.

## Análisis observado (Fase 4.1)

El adaptador de ventas debe suministrar kind (sale, return, return_reversal) y unidad base verificable; el signo por sí solo no clasifica. ObservedDemandQuery funciona sin persistencia y usa la zona del consumidor. Para almacenar caché y métricas se requiere aplicar previamente la nueva migración mediante replenishment:migrate; nunca se ejecuta automáticamente. Consultar [análisis observado](demanda-observada.md).

## Diagnóstico histórico de inventario

El consumidor puede resolver `StockHistoryQuery` mediante el contenedor y llamar `analyze(productId, locationId, from, until, salesMode)`. Los modos comerciales son `none`, `source` y `cache`. Los límites deben ser medianoches operativas y excluir el día en curso. El resultado no se guarda. El comando del consumidor es `php artisan replenishment:stock-history PRODUCTO --from=2026-10-17 --until=2026-11-01 --location=UBICACION`; `--sales` y `--sales-cache` son opcionales y excluyentes. La ubicación debe corresponder al adaptador configurado. Véase [criterios y límites](inventario-historico.md).

## Estacionalidad observada

`SeasonalityQuery::analyze(productId, locationId, from, until, cached)` devuelve un diagnóstico sin guardar. `cached=true` exige la tabla propia existente y nunca cae silenciosamente a ERP. La conexión propia no se abre en consulta directa. `SeasonalityStore::persist(result)` es explícito, rechaza insuficiencia y añade patrones/auditoría atómicamente. El comando del consumidor es `replenishment:seasonality`, con `--from`, `--until`, `--location`, `--cache` y `--persist`. Caché y persistencia son compatibles: se pueden guardar patrones derivados de caché, dejando trazadas sus fechas; no se reconstruye esa caché. Véase [configuración y criterios](estacionalidad.md).

## Pronósticos mensuales

`ForecastQuery::analyze(productId, locationId, from, until, horizon, cached, backtest)` requiere límites mensuales completos; el fin exclusivo es el origen del pronóstico. La consulta directa no abre la conexión propia. Caché exclusiva no consulta ERP ni se reconstruye automáticamente. El comando del consumidor `replenishment:forecast` admite `--horizon`, `--cache`, `--backtest`, `--no-backtest` y `--persist`; únicamente las dos opciones de backtesting se excluyen entre sí. `ForecastStore::persist(result)` añade cantidades conocidas y su auditoría, conservando categorías exploratorias. Véase [reglas y límites](pronosticos.md).

## Proveedores y plazos (Fase 6.1)

La integración añade replenishment:supplier-lead-time PRODUCTO PROVEEDOR --from=YYYY-MM-DD --until=YYYY-MM-DD, con --location y --persist opcionales. La consulta predeterminada sólo lee y funciona sin tablas propias; advierte entonces que los plazos manuales almacenados no están disponibles. La publicación requiere relación propia existente y migración evolutiva aplicada explícitamente, nunca automática. No se aplicó a bases reales.

Configurar replenishment.supplier_lead_time y, sólo si existe una decisión comercial expresa, replenishment.policy_defaults.default_lead_time_days o policy_id. La conexión ERP continúa independiente de REPLENISHMENT_DB_CONNECTION. Los ejemplos anteriores de MySQL y SQL Server designan nombres de conexión Laravel, no drivers. Consultar [configuración manual, evidencia y contrato de salida](plazos-proveedores.md).

## Planificación de Fase 6.2

El consumidor incorpora replenishment:plan PRODUCTO PROVEEDOR con horizonte mensual, objetivo, presentación e hipótesis explícitas. No hay escritura salvo --persist. No reemplaza cantidades desconocidas por cero ni genera órdenes oficiales. Un mes parcial necesita consumo previo explícito para simular; la demanda manual declara unidad base. La conexión ERP sigue independiente de la persistencia propia. Véase [opciones, códigos de salida y ejemplos](reposicion.md).

## Administración comercial HTTP (Fase 7.1)

consult-web expone /api/replenishment/config/{units|suppliers|presentations|policies} con GET/POST/PATCH. Autenticación Sanctum y permisos separados replenishment.view/replenishment.manage mediante comprobación Spatie directa. Gate::before existente tiene un conflicto de autorización personal documentado; no se modificó globalmente. El registro de permisos y su asignación deben efectuarse explícitamente en la administración existente, sin privilegios automáticos. Véase [endpoints, autenticación Bearer y ejemplos](configuracion-comercial.md).

## Interfaz web del consumidor (Fase 7.2)

consult-web incorpora /replenishment, /replenishment/configuration/{units|suppliers|presentations|policies} y /replenishment/simulator. Los formularios invocan CommercialConfiguration directamente; la simulación JSON invoca ReplenishmentQuery sin publicar resultados. La sesión Jetstream y CSRF protegen estas rutas; los permisos Spatie replenishment.view y replenishment.manage se comprueban por separado. La API Sanctum de 7.1 conserva su comportamiento y el frontend no requiere tokens Bearer.

La documentación operativa está en docs/replenishment-interfaz.md del consumidor: rutas, componentes, confirmaciones, paginación, revisiones, estados y límites. La evaluación se limita a hoy para no atribuir reservas actuales a otras fechas; el horizonte es de uno a seis meses y el entrenamiento queda acotado a 24 meses. Las cantidades manuales permanecen como cadenas y requieren unidad base. No se ejecutan migraciones al navegar ni se guardan simulaciones.

Se verificaron nueve pruebas HTTP focalizadas con 88 aserciones en SQLite y la compilación Vite del consumidor. Esto no amplía la matriz de compatibilidad del paquete. MySQL aislado y revisión visual/manual siguen pendientes; no se iniciaron servidores, navegadores ni Fase 7.3.

## Ajuste 7.2.1

Consultar [modalidades de instalación y migración](migraciones.md) antes de usar vendor:publish --tag=replenishment-migrations. El tag es voluntario para instalaciones nuevas; consult-web mantiene su historial dedicado y no recibió publicaciones ni migraciones automáticas.

SupplierProductSource permite implementar una fuente acotada de candidatos en el consumidor. SupplierImportService ofrece start, advance y confirm; la interfaz web del consumidor adapta esos pasos a sesión/CSRF y lotes reanudables. Véase [carga inicial](carga-masiva.md). La verificación de proveedor en CommercialConfiguration normaliza espacios exteriores/mayúsculas y guarda el código devuelto por SupplierSource; ProductSource conserva su semántica original.

## Ajuste 7.2.2

El consumidor usa POST /replenishment/presentations/bulk con sesión, CSRF y permiso replenishment.manage para llamar a BulkPresentationCreator. GET /replenishment/relations ofrece selección paginada con permiso view. La configuración web entrega page/paginator y conserva filtros; API Sanctum y opciones existentes mantienen sus cursores. La transición afecta sólo a la página web y debe desplegarse junto con el frontend.

La documentación del consumidor docs/replenishment-usabilidad-722.md describe los totales entre motores, archivo temporal de IDs, límites de lote, máscaras visuales y pruebas. No requiere nuevas tablas ni comandos de migración. El paquete sigue independiente de Vue y no incorpora presentación decimal ni máscaras de códigos en sus reglas de dominio.

## Transporte guiado 7.2.3

El consumidor consulta opciones de presentaciones con `relation`, `after` y `valid_only=1`; aplica relación, vigencia y estados activos antes de limitar a 25, con una fila adicional para detectar continuación. Sin `valid_only` se conserva consulta histórica por cursor. No requiere cambios del paquete ni nuevas migraciones.

El formulario envía cadenas decimales exactas con punto, convirtiendo únicamente la coma decimal introducida. El servidor exige objetivo en primer día de mes, fecha de evaluación de hoy y horizonte acotado. Una presentación ausente se admite para diagnóstico; el motor mantiene la incertidumbre correspondiente y no genera una compra válida. La unidad base verificada se invalida al cambiar producto/proveedor.

La recarga Inertia tras altas actualiza sólo filas y paginación; conserva filtros y resúmenes. No persiste simulaciones ni selecciona políticas implícitas. Consulte la guía `consult-web/docs/replenishment-simulador-723.md`; la revisión visual y motores reales aislados continúan pendientes.

## Propuestas internas (Fase 7.3)

El consumidor invoca `PurchaseProposalService` y `PurchaseProposalQuery` desde rutas web autenticadas. `ProposalScenario` cifra evidencia efímera ligada al actor y recalcula el escenario antes de incorporarlo. Cada comando tiene UUID; las modificaciones incluyen versión esperada y las aprobaciones, huella de advertencias. `ProposalCommandRequest` concentra reglas y mensajes, utilizados por el controlador para distinguir Inertia y JSON. Las respuestas JSON conservan 403/404/409/422; errores de formularios Inertia redirigen con valores y errores según su protocolo.

Se requiere registrar y asignar expresamente `replenishment.approve` con guard web, sin concesiones automáticas. El seeder existente sólo añade la definición. La migración evolutiva debe ser revisada y ejecutada por el operador en la modalidad ya utilizada. Consulte [Propuestas](propuestas.md) y la guía del consumidor. No se ejecutaron migraciones ni se asignaron permisos reales.

## Ajuste 7.3.1 del consumidor

La navegación web distingue la sección activa por rutas exactas. El selector múltiple incorpora pendientes por defecto y orden global por unidad base, código/nombre y relación antes de paginar. Se resuelve en el consumidor mediante lecturas por bloques y mezcla de archivos temporales por solicitud, sin cambios del núcleo ni migraciones. La selección y el resumen se conservan al refrescar después del alta. Guía: `consult-web/docs/replenishment-usabilidad-731.md`.


## Ajuste 7.3.2 — Generación por política

Se añade generación durable por bloques, historia anual/mensual/semanal, decisiones auditadas y costo opcional con semántica explícita. Consulte [la documentación](generacion-politica-732.md). La migración 000006 requiere ejecución manual posterior; no se migró consult-web. La verificación de costos, motores reales y concurrencia entre procesos permanece diferenciada de SQLite y de consultas SELECT.

## Fase 7.4.1 — Integración del dashboard

Los servicios `ReplenishmentDashboardQuery`, `DashboardSalesQuery` y `DashboardAnalysisQuery` se resuelven mediante el contenedor y modelos propios. El consumidor aplica `DashboardScope`, sesión y permiso directo de consulta. No invocar consultas directas de pronóstico, estacionalidad o demanda al abrir una pantalla. Consulte [Dashboard 7.4.1](dashboard-741.md).

## Consulta de historia comercial

El adaptador optativo `commercial_history` se resuelve por `AdapterRegistry`. La configuración local controla exclusiones y corte por producto. La CLI `replenishment:demand` admite consulta directa, fotografía materializada y publicación expresa. Véanse [perfiles, migración propia y consultas de la etapa C](ajuste-733.md#cli-y-despliegue-posterior).
