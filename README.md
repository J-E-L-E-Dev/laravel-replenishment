# Laravel Replenishment

Paquete independiente con nombre provisional `asisnet/laravel-replenishment`. La Fase 1 establece contratos de lectura, la Fase 2 añade persistencia propia y la Fase 3.1 permite calcular disponibilidad actual y la Fase 3.2A incorpora contratos y calidad de movimientos históricos. La Fase 3.2B agrega órdenes, recepciones y conciliación con incertidumbre explícita. La Fase 4.1 calcula ventas históricas observadas, series y promedios; no estima demanda perdida, pronósticos ni reposición.

## Contenido

- Ocho contratos de productos, inventario físico, movimientos históricos, reservas, ventas, compras pendientes, historial de compras y proveedores.
- DTO inmutables con identificadores opacos y cantidades decimales exactas como cadenas.
- Proveedor con descubrimiento automático, configuración publicable y resolución diferida de adaptadores.
- Comando informativo `php artisan replenishment:status`.
- Doce tablas de dominio y una caché diaria reconstruible, con modelos Eloquent y métricas históricas inmutables.
- Migración reversible mediante `replenishment:migrate`, con conexión y registro independientes del migrador general.
- Decimales exactos, revisiones comerciales e instantáneas históricas, con pruebas SQLite aisladas.
- Disponibilidad exacta por producto y ubicación, con reservas clasificadas, desconocidos y sobrecompromiso negativo.

## Instalación

El paquete aún no está publicado en Packagist. Para desarrollo local, seguir [la integración](docs/integracion.md). Su manifiesto no depende de rutas locales: una publicación futura podrá instalarse desde un repositorio Composer con una versión etiquetada.

La configuración es opcional y puede publicarse con:

```sh
php artisan vendor:publish --provider="Asisnet\Replenishment\ReplenishmentServiceProvider" --tag=replenishment-config
```

Todos los adaptadores empiezan sin configurar. El arranque y el diagnóstico funcionan así; solicitar datos mediante el registro requiere un adaptador explícito.

La persistencia usa `replenishment.connection`, configurable mediante `REPLENISHMENT_DB_CONNECTION`. El valor es el **nombre de una conexión Laravel existente**, no su driver. Si se omite, se utiliza la predeterminada de Laravel. Los adaptadores ERP conservan sus propias conexiones.

Las tablas y su registro se gestionan exclusivamente con `php artisan replenishment:migrate` y `php artisan replenishment:migrate --rollback`. El comando general `migrate` no incluye el paquete. Consultar [integración](docs/integracion.md) para ejemplos MySQL/SQL Server y la conciliación de instalaciones anteriores.

## Pruebas

```sh
composer install
composer test
```

Las pruebas usan Orchestra Testbench, PHPUnit y SQLite en memoria, sin conectarse a bases del consumidor. Consultar [verificación de la Fase 2](docs/verificacion-fase-2.md) antes de considerar una combinación validada. SQL Server 2017 tiene revisión de gramática, pero su ejecución real permanece pendiente.

## Documentación

- [Arquitectura y semántica de contratos](docs/arquitectura.md).
- [Integración del consumidor](docs/integracion.md).
- [Diseño y reglas de persistencia](docs/persistencia.md).
- [Disponibilidad y compromisos actuales](docs/disponibilidad.md).
- [Ventas y movimientos históricos](docs/historicos.md).
- [Compras, recepciones y conciliación](docs/compras.md).
- [Ventas y demanda histórica observada](docs/demanda-observada.md).
- [Normas para futuras fases](AGENTS.md).

## Distribución

Nombre provisional y licencia `proprietary` como marcador conservador: la licencia de distribución deberá decidirla el titular antes de publicar. No hay una versión estable ni repositorio remoto configurados.




La Fase 4.2 incorpora el [diagnóstico de inventario histórico](docs/inventario-historico.md), sin inferir disponibilidad comercial ni demanda perdida.

La Fase 5.1 incorpora [estacionalidad mensual de ventas observadas](docs/estacionalidad.md), con auditoría histórica y protección de caché incompleta.

La Fase 5.2 incorpora [pronósticos mensuales y validación retrospectiva](docs/pronosticos.md), sin calcular compras ni demanda perdida.

La Fase 6.1 incorpora [plazos de proveedores](docs/plazos-proveedores.md): evidencia documental, configuración manual explícita y estadísticas por destino. No genera recomendaciones de compra.

La Fase 6.2 añade [planificación de reposición y simulaciones explícitas](docs/reposicion.md), sin generar órdenes oficiales. Conserva los estados de incertidumbre y todas las limitaciones comerciales previas.

La Fase 7.1 añade [administración comercial reusable](docs/configuracion-comercial.md), validación de identidades externas y presentaciones versionadas con integración HTTP autorizada. No introduce valores comerciales implícitos.

La Fase 7.2 integra en el consumidor una interfaz Inertia/Vue para configuración comercial y simulaciones sin publicación. Reutiliza los servicios del paquete y conserva su independencia del frontend. Véase [integración web](docs/integracion.md).

El ajuste 7.2.1 añade normalización canónica de proveedores, sugerencias de plazo y [carga masiva reanudable](docs/carga-masiva.md). Las instalaciones nuevas pueden optar por [publicar envoltorios de migración estándar](docs/migraciones.md); las instalaciones dedicadas existentes conservan su historial. La ampliación de control de importaciones no se aplica automáticamente.

El ajuste 7.2.2 incorpora creación común explícita de presentaciones mediante BulkPresentationCreator, manteniendo validación transaccional e históricos. El consumidor añade paginación real, formato decimal textual y ayudas de códigos, sin cambios de esquema ni cálculos. Véase [administración comercial](docs/configuracion-comercial.md).

## Ajuste 7.2.3: simulador guiado del consumidor

La integración web de consult-web distingue identidad y unidad ERP, configuración comercial e hipótesis. Incorpora presentaciones vigentes filtradas antes de paginar, fechas mensuales, cantidades españolas exactas, diagnóstico incompleto y resultados desactualizados al editar. Las altas masivas recargan listados conservando filtros. No se modificaron motores, esquema ni contratos matemáticos; las simulaciones siguen sin persistirse. La guía operativa está en `consult-web/docs/replenishment-simulador-723.md`. Política activa no significa política utilizada, y nivel de servicio no significa seguridad calibrada.

## Fase 7.3: propuestas de compra internas

Se incorporan servicios de propuestas, evidencia por línea, decisiones manuales, revisión, aprobación humana e historial inmutable. [Flujo, persistencia y límites](docs/propuestas.md). Una migración evolutiva añade versión, origen por línea y eventos; no se ejecuta automáticamente. La aprobación no crea órdenes ERP ni convierte hipótesis en pronósticos certificados. No se inició Fase 7.4.


## Ajuste 7.3.2 — Generación por política

Se añade generación durable por bloques, historia anual/mensual/semanal, decisiones auditadas y costo opcional con semántica explícita. Consulte [la documentación](docs/generacion-politica-732.md). La migración 000006 requiere ejecución manual posterior; no se migró consult-web. La verificación de costos, motores reales y concurrencia entre procesos permanece diferenciada de SQLite y de consultas SELECT.

## Fase 7.4.1 — Dashboard analítico y operativo

Consultas reutilizables de propuestas, generaciones, ventas materializadas y versiones de análisis, sin recálculo ni escrituras. [Arquitectura, indicadores, pruebas y limitaciones](docs/dashboard-741.md). La Fase 7.4.2 permanece fuera del alcance.
