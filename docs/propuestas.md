# Propuestas internas y auditoría — Fase 7.3

## Responsabilidades

`PurchaseProposalService` concentra altas, cantidades explícitas, validación comercial, auditoría e idempotencia. Reutiliza `ReplenishmentCalculator::packages` y `Brick\Math`: una decisión humana incompatible con mínimos o múltiplos se rechaza sin redondear. `PurchaseProposalQuery` aplica filtros y paginación real. Se reutiliza el DTO inmutable `ReplenishmentPlanData` para resultados recalculados; no se añade otro DTO equivalente ni dependencias de interfaz al paquete.

El consumidor autentica y verifica permisos, emite evidencia cifrada efímera del simulador, recalcula antes de incorporar y usa los servicios directamente. Los parámetros e identidades de Vue no sustituyen verificaciones de fuentes. Sólo la acción expresa de agregar persiste mediante `ReplenishmentStore`, junto a propuesta/línea/evento en una transacción de `PersistenceConnection`.

Cada cabecera pertenece a proveedor y ubicación lógica. No se usa un maestro ni modelo User del consumidor dentro del paquete. Los actores son identificadores textuales de sesión, suministrados por el transporte autorizado. La fuente proveedor/producto se resuelve mediante contratos, sin consultas ni tablas ERP en el núcleo.

## Evidencia por línea

La cabecera conserva su vínculo heredado `replenishment_run_id`, pero las nuevas propuestas no lo utilizan como ejecución común ficticia. Cada línea calculada enlaza su propio `source_result_id`; éste conduce a su ejecución y resultado inmutables. `evidence` conserva el origen, informe, hipótesis, cantidades y advertencias. `decision` conserva solicitud vigente, equivalencias exactas, unidad, descripción, presentación, motivo y fotografía aprobada. Los cambios generan eventos antes/después sin sobrescribir el origen.

En una decisión manual, `evidence.original` permanece NULL. El campo heredado obligatorio `suggested_base_units` conserva un resumen inicial por compatibilidad estructural; no significa que exista un pronóstico calculado. Los lectores nuevos deben utilizar evidencia y decisión, no inferir procedencia de la columna heredada. Los resúmenes decimales usan seis posiciones HALF_UP; JSON conserva la operación exacta. Excedente respecto a necesidad desconocida permanece NULL.

Retirar una línea marca `removed_at`; no elimina su evidencia. No se admiten duplicados activos del mismo producto dentro de una propuesta. Las coincidencias en otras propuestas borrador, en revisión o aprobadas internamente requieren advertencia y aceptación de división de compra. No constituyen entradas ERP.

## Estados

`draft` permite editar y pasa a `in_review` o `cancelled`. `in_review` permite `approved`, `rejected`, `draft` o `cancelled`. Devolver, rechazar, cancelar y retirar requieren motivo. Aprobación/rechazo requieren capacidad de aprobación autenticada, nunca un booleano tomado del cuerpo HTTP. Aprobar requiere confirmación explícita y huella vigente de las advertencias mostradas. No hay edición en revisión; un cambio de cantidades requiere devolución a borrador, ajuste justificado y nuevo envío.

La aprobación fija las cantidades solicitadas como aprobadas y registra actor y fecha. No promueve simulaciones a pronósticos certificados. Los estados finales y eventos son inmutables mediante modelos. Una propuesta nueva puede enlazar un antecedente cerrado, pero no copia automáticamente sus líneas. Los registros antiguos sin `creation_key` y eventos deben conciliarse antes de operarlos; no se adoptan.

Una revisión comercial histórica identificada puede conservarse al aprobar si sigue activa y válida. Una selección nueva/ajuste exige revisión vigente; nunca se cambia automáticamente. La revisión visible advierte versiones anteriores y coincidencias simultáneas. Cambios de advertencias entre lectura y aprobación provocan conflicto.

## Persistencia y concurrencia

La migración `2026_11_10_000005_add_proposal_workflow.php` añade campos estructurales a las dos tablas existentes y crea `replenishment_proposal_events`. Sus claves foráneas sólo apuntan a tablas propias y usan NO ACTION. La ampliación no modifica migraciones antiguas, tipos decimales ni tablas ERP. SQLite recibe las referencias añadidas mediante DDL explícito en la conexión propia, porque Laravel 10 no incorpora esas claves externas al alterar tablas; su reversión elimina columnas nuevas sin solicitar el DROP FOREIGN KEY no soportado por SQLite.

Versión de cabecera y bloqueo de fila serializan comandos. Eventos tienen UUID único global y una versión única por propuesta. El UUID se vincula a actor y contenido: reintentos iguales no repiten altas; contenido distinto genera conflicto. La creación concurrente que choque con el índice de evento se revierte completamente y puede reintentarse con la misma clave. SQL directo/actualización masiva no son API de dominio y omiten protecciones Eloquent.

La transacción no congela el ERP; no existe instantánea distribuida. La evidencia del simulador se recalcula y compara antes de guardar. La comparación conserva datos comerciales, fechas e hipótesis y omite únicamente tiempos técnicos de generación; observación actual se compara por día operativo. Ningún pendiente de propuesta se añade a `PurchaseSource`.

## Costos y despliegue

La semántica previa verificada en `docs/persistencia.md` establece costo estimado por unidad base. Esta fase no incorpora precios vigentes fiables, monedas supuestas, impuestos ni totales monetarios. Las nuevas líneas mantienen costo y moneda NULL, sin impedir aprobar cantidades.

Instalaciones dedicadas continúan con `php artisan replenishment:migrate`; las estándar disponen de un envoltorio adicional que reutiliza la misma definición y valida conexión/registro. No mezclar modalidades ni publicar/migrar automáticamente el consumidor. Antes de cualquier reversión respaldar evidencia: el rollback elimina campos/eventos de esta fase. No se ejecutaron migraciones reales.

La guía del consumidor `docs/replenishment-propuestas.md` describe pantallas, permisos y operación. `replenishment.approve` es un permiso web nuevo que debe registrarse y asignarse expresamente. La aprobación es sólo interna; no hay órdenes oficiales, envío al proveedor, reservas, movimientos ERP, cambios de mínimos/máximos ni Fase 7.4.
