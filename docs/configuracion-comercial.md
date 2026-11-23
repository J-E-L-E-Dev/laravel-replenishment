# Configuración comercial y persistencia operativa — Fase 7.1

## Servicio reusable y separación

CommercialConfiguration administra units (PurchaseUnit), suppliers (relaciones ProductSupplier), presentations (SupplierPresentation) y policies (Policy). Los nombres de recurso forman una lista cerrada; no se acepta una clase, conexión o tabla elegida por el cliente. Los contratos ProductSource y SupplierSource validan identidades externas exactas antes de crear o editar relaciones, sin duplicar maestros ni consultar últimas compras para inferir preferencias.

La lógica reside en el paquete, con create, find, list, update, validate e isCurrent. No depende de User, Jetstream, controladores o tablas ERP. Las escrituras se realizan mediante modelos y transacciones PersistenceConnection. Las consultas se limitan a cien filas, ordenadas por id, con cursor after exclusivo. No hay borrado físico en el servicio ni en HTTP: se usa active=false. Las claves foráneas existentes siguen protegiendo referencias históricas.

No se crean unidades comerciales, relaciones, conversiones, preferencias ni políticas por defecto. Los únicos parámetros comerciales heredados son los policy_defaults ya configurados explícitamente por el consumidor, conforme al modelo anterior.

## Reglas de configuración

**Unidades comerciales:** code y name al crear; description y active opcionales. Después se edita nombre, descripción y estado. code identifica la unidad y no se cambia: para otro código se crea otra unidad. No representa automáticamente una unidad de inventario.

**Relaciones:** product_id y supplier_id son cadenas originales, incluidos ceros iniciales. Deben existir y coincidir exactamente con los contratos. Unicidad producto/proveedor, también cuando la relación está inactiva. Identidades no modificables; notes, active, is_preferred y manual_lead_time_days sí son editables. Cero significa entrega explícita el mismo día; NULL revoca el plazo. No se exige historia certificada para registrar un plazo manual. Preferencia sólo cambia si se solicita expresamente; no se infiere del último proveedor ni se fuerza exclusividad no definida por el modelo.

**Presentaciones:** product_supplier_id y purchase_unit_id seleccionan una presentación concreta. Factor y múltiplo positivos, mínimo no negativo, decimales exactos de hasta seis posiciones. El transporte HTTP requiere cantidades decimales como cadenas; el servicio rechaza float. Nunca se interpreta C02091.unidad como empaque.

Se pueden configurar varias unidades comerciales por relación y distintas relaciones/proveedores por producto. Cambiar factor, mínimo o múltiplo crea una nueva fila con revision anterior + 1. Un cambio sólo de active conserva la fila. La revisión inicial es 1 y el cliente no puede imponer revision. Una nueva revisión conserva los atributos no modificados y el estado anterior salvo cambio explícito. La revisión vieja no se elimina ni altera; puede conservar active=true, pero ya no es vigente.

Vigencia es la revisión máxima **de la misma relación y unidad comercial**, aunque esté inactiva. No se vuelve silenciosamente a una revisión antigua ni se selecciona otra presentación. Una petición de cambio de conversión sobre revisión obsoleta produce conflicto. Tampoco se permite reactivarla; sí se puede desactivar un registro antiguo. La relación propietaria se bloquea durante la creación de revisiones y se conserva la unicidad de la base; un conflicto concurrente revierte la operación.

**Políticas:** se reutilizan todos los campos actuales. Una política puede guardarse incompleta e inactiva. Activarla exige analysis_days, target_coverage_days, service_level, default_lead_time_days, minimum_history_days, seasonal_minimum_years y forecast_horizon_days coherentes. No se convierte service_level en unidades de seguridad ni se añade jerarquía por sucursal.

La corrección focalizada en ExactDecimal permite que Eloquent compare valores de casts al editar políticas, incluidos cambios desde NULL. Conserva el comparador estático de dos escalares y añade el protocolo de cuatro argumentos que Laravel utiliza para decidir igualdad. No se introducen floats ni se cambia escala.

## Ejemplo del servicio

```php
$service = app(\Asisnet\Replenishment\Services\CommercialConfiguration::class);
$unit = $service->create('units', ['code' => 'BOX', 'name' => 'Caja']);
$relation = $service->create('suppliers', [
    'product_id' => '0001', 'supplier_id' => '0002',
    'manual_lead_time_days' => 7,
]);
$presentation = $service->create('presentations', [
    'product_supplier_id' => $relation->id,
    'purchase_unit_id' => $unit->id,
    'base_units_per_package' => '12',
    'minimum_packages' => '0', 'package_multiple' => '1',
]);
$newRevision = $service->update('presentations', $presentation->id, [
    'base_units_per_package' => '24',
]);
```

Identidades y conversiones del ejemplo son ficticias y deben ser introducidas comercialmente; el ejemplo no se ejecutó en la base comercial. Una fuente ERP inaccesible es un fallo de servicio, no prueba que el maestro no exista.

## HTTP en consult-web

Base: /api/replenishment/config. Resources: units, suppliers, presentations, policies. suppliers representa relaciones propias, no un maestro duplicado de proveedores.

| Método | Ruta | Permiso | Resultado |
|---|---|---|---|
| GET | /{resource}?after=0&limit=50 | replenishment.view | Listado acotado, data y next_after |
| GET | /{resource}/{id} | replenishment.view | Registro exacto |
| POST | /{resource} | replenishment.manage | Alta explícita |
| PATCH | /{resource}/{id} | replenishment.manage | Edición o nueva revisión |

No existe DELETE. Las presentaciones exponen is_current sin sustituir la identidad elegida. PATCH de factor/mínimo/múltiplo devuelve 201 y el **nuevo id**; una edición ordinaria devuelve 200. El consumidor debe usar ese nuevo id en futuros diagnósticos. Los históricos conservan su referencia y fotografía originales.

Códigos: 200 consulta/edición, 201 alta/revisión, 401 no autenticado, 403 permiso ausente, 404 registro/referencia inexistente, 409 duplicado o conflicto de identidad/revisión, 422 entrada o regla comercial inválida, 503 infraestructura/fuente no disponible. Las respuestas no revelan SQL, credenciales ni trazas. FormRequest ofrece mensajes en español y el paquete vuelve a validar tipos y reglas para otros consumidores.

Ejemplo de cuerpo de alta:

```json
{
  "product_supplier_id": 1,
  "purchase_unit_id": 2,
  "base_units_per_package": "12.500000",
  "minimum_packages": "0",
  "package_multiple": "1",
  "active": true
}
```

Los ids del ejemplo son ilustrativos. product_id/supplier_id externos se envían siempre como cadenas, nunca números JSON.

## Autenticación y conflicto de Gate

Se revisó AuthServiceProvider antes de conectar rutas. Su Gate::before actual concede acceso a una lista personal por correo y devuelve false para los demás. Por tanto, can() no representa de forma fiable los permisos Spatie de este módulo: puede conceder acceso sin permiso o denegarlo a alguien que sí lo posee.

No se modificó ese comportamiento global ni se añadieron excepciones personales. ReplenishmentAccess autentica mediante el guard sanctum y comprueba directamente hasPermissionTo('replenishment.view' o 'replenishment.manage', 'web'), reutilizando permisos directos y de roles de Spatie. Un permiso inexistente se deniega. Si hay token, exige también tokenCan para esa operación. Un Gate global permisivo no concede acceso al módulo y uno restrictivo no sustituye su comprobación explícita. La decisión se limita a estas rutas.

Consulta y administración son permisos separados: manage no implica view. Para un administrador que necesite ambos, deben asignarse ambos explícitamente mediante la administración de permisos existente. No se crearon permisos ni asignaciones en la base comercial durante esta fase.

La integración API actual no tiene habilitado EnsureFrontendRequestsAreStateful. Para estas llamadas se documenta autenticación Bearer de Sanctum, con capacidad del token coincidente y permiso Spatie del usuario. No se habilitó autenticación SPA ni se alteraron sesiones, CSRF o configuración global. No hay pantallas nuevas.

El módulo no recibe referencias de calibración, no aprueba recomendaciones y no genera órdenes. Una referencia externa sin autenticar no es una garantía comercial ni habilita aprobaciones automáticas.

## Persistencia y validación de motores

Se mantiene REPLENISHMENT_DB_CONNECTION=mysql en el consumidor; no se modificó su configuración ni la conexión SQL Server. No hay nuevas migraciones. Los esquemas inicial/evolutivos y el registro dedicado siguen bajo replenishment:migrate.

Pruebas del paquete y de HTTP usan SQLite temporales, con conexiones own/erp o mysql/sqlsrv independientes. Se ejercitan altas, consultas, cambios, desactivación, identidades inexistentes/duplicadas, precisión, políticas incompletas, revisión vigente, conflictos, fotografía histórica, rollback posterior al INSERT, permisos, tokens y aislamiento.

Se buscó una base MySQL de pruebas identificable mediante consulta de metadatos usando la configuración existente. No se identificó ninguna de las bases de pruebas buscadas; no se creó una nueva ni se migró la base comercial. **La validación operativa MySQL está pendiente**, incluidos DDL, restricciones, concurrencia, precisión y transacciones reales. SQLite no la certifica. La prueba debe ejecutarse posteriormente en una base aislada expresamente identificada, con migraciones iniciales/evolutivas, registro dedicado y reversión.

## Integración con identidades reales

El 09-11-2026 se consultó el primer producto de la orden 800/00001330 y dos proveedores tipo P del maestro mediante contratos existentes. Todas las identidades coincidieron exactamente; no se copiaron nombres o códigos reales a fixtures permanentes.

En un contenedor de prueba se sustituyó **sólo en memoria** el destino propio mysql por SQLite :memory:, comprobando driver y destino antes de migrar. La conexión ERP siguió siendo SQL Server de sólo lectura. Se crearon dos relaciones ficticias para ese producto, plazo manual 7 y presentaciones ficticias de 12 y 24 unidades. La primera se revisó a factor 10, conservando la revisión de factor 12.

ReplenishmentQuery de 6.2 consumió esa configuración sin modificaciones del motor. Ambos planes identificaron correctamente producto, proveedor, presentación, unidad base y plazo manual. C1 seleccionó revisión 2/factor 10; C2 revisión 1/factor 24. Ambos fueron simulation por demanda, seguridad, consumo previo y ausencia de entrantes supuestos. No se promovieron a recommended. El contraste emitió 29 SELECT y cero escrituras ERP; las configuraciones ficticias desaparecieron al cerrar SQLite en memoria.

La siguiente fase no se ha iniciado. La operación real requiere instalar esquema propio explícitamente, asignar permisos, introducir equivalencias verificadas y completar la prueba MySQL aislada; ninguna de esas condiciones se sustituye por inferencias automáticas.

## Resultado de pruebas focalizadas

- Administración del paquete: 8 pruebas, 53 aserciones satisfactorias.
- HTTP, autenticación y permisos reales Spatie sobre SQLite: 4 pruebas, 31 aserciones satisfactorias.
- Regresión focalizada de casts, políticas y fotografías: 6 pruebas, 24 aserciones satisfactorias.
- Migraciones iniciales/evolutivas y rollback con tabla ajena conservada: 1 prueba, 27 aserciones satisfactorias.

Total: 19 pruebas y 135 aserciones. Paquete Laravel 12/PHPUnit 11.5.57; consumidor Laravel 10/PHPUnit 10.5.29, ejecutados con PHP 8.2.28. Nueve archivos PHP nuevos/modificados pasan lint PHP 8.1.33. No se repitió la matriz completa ni se ampliaron dependencias Composer. MySQL de pruebas sigue pendiente y no se presenta como validado mediante SQLite.

## Ajuste 7.2.1

La entrada de proveedor admite espacios exteriores y minúsculas. CommercialConfiguration::supplier consulta el código normalizado mediante SupplierSource y devuelve el identificador canónico que se persiste, también desde API y carga masiva. Los códigos de producto conservan su tratamiento previo. suggestedLeadTime consulta sólo relaciones activas del mismo proveedor con plazo no NULL, ordenadas por created_at e id descendentes. Es una sugerencia editable específica de la relación nueva, no una política global ni una actualización de relaciones anteriores.

La interfaz conserva un plazo editado manualmente y muestra procedencia. Incorpora filtros propios por código/nombre de unidad y código de producto/proveedor; las descripciones externas se consultan en bloques sin joins entre motores. Véase [carga masiva](carga-masiva.md).

## Creación común explícita (7.2.2)

BulkPresentationCreator::create recibe de una a cien relaciones distintas y una configuración común: purchase_unit_id, base_units_per_package, minimum_packages, package_multiple y active. Las cantidades continúan como cadenas. El consumidor debe obtener confirmación expresa de equivalencia; compartir proveedor no la demuestra.

El servicio bloquea las relaciones en orden de ID y la unidad comercial dentro de PersistenceConnection. Revalida estado, producto, proveedor y unidad base mediante contratos. Una referencia no verificable o unidades base incompatibles bloquean el lote completo antes de crear. Las presentaciones existentes de la misma relación/unidad se omiten, incluidas revisiones históricas o inactivas; nunca se añaden revisiones implícitas. Los cambios individuales mantienen CommercialConfiguration::update.

La respuesta separa requested, created, existing, conflicts, errors y status. Un fallo durante las altas revierte todo el lote. El motor matemático, los casts, las precisiones y los contratos API existentes no cambian. El formato visual que elimina ceros finales no provoca revisiones cuando el valor normalizado permanece igual.

El servicio no consulta tablas específicas de OrbisNET ni administra transporte HTTP. Las rutas web, paginación entre motores, selección y confirmación pertenecen al consumidor. No hay nuevas migraciones.
