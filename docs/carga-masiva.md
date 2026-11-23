# Carga inicial de relaciones por proveedor

SupplierImportService es reutilizable e independiente del ERP. Recibe SupplierProductSource para obtener candidatos en bloques de hasta cien códigos y usa CommercialConfiguration para validar identidades y crear relaciones. El adaptador del consumidor decide cómo obtiene esos códigos; el núcleo no contiene nombres de tablas ERP.

## Operación durable

start comprueba proveedor y opciones expresas y crea un UUID vinculado al actor. No crea relaciones. advance prepara la vista previa mediante bloques ordenados con cursor exclusivo, mostrando encontrados, nuevas posibles, existentes, conflictos y errores de identidad. confirm sólo acepta la versión de una vista previa completa y congela sus conteos; después advance ejecuta bloques transaccionales.

La operación guarda opciones, actor, proveedor, timestamps, versión, cursor, conteos acumulados, conteos de la vista previa y detalle del último bloque. Esa identificación permite consultar y reanudar una operación sin crearla de nuevo. El detalle de bloques anteriores no constituye un historial individual exportable; la auditoría conservada es la de operación y último bloque.

Las altas y el nuevo cursor se confirman juntos mediante PersistenceConnection. Un fallo de infraestructura revierte todo el bloque: se conservan los bloques anteriores y la misma versión puede reintentarse. Repetir una petición con versión antigua sólo devuelve el estado actual. Una carga nueva vuelve a verificar la unicidad producto-proveedor y omite las relaciones ya creadas.

Cada candidato se verifica con ProductSource. Una identidad ausente o inconsistente cuenta como error; una caída de fuente interrumpe el bloque y no se presenta como ausencia. Los conflictos de preferido se omiten completos: no se sustituye otro proveedor ni se crea silenciosamente una relación con preferencia distinta. Se respeta cualquier preferencia previa, incluida una relación inactiva. No se sobrescriben plazos, notas, estados ni relaciones previas, y no se crean presentaciones o conversiones.

## Alcance temporal y concurrencia

La vista previa es informativa, no una instantánea bloqueada del ERP. La ejecución vuelve a leer el catálogo y verifica el estado actual. Cambios concurrentes pueden modificar sus conteos. Un código añadido por detrás del cursor se incluirá en una nueva operación, no se presume incorporado en la ya recorrida.

El servicio bloquea el registro de operación y las relaciones examinadas dentro de cada transacción. El consumidor añade un bloqueo compartido para evitar lotes web de importación simultáneos. No se han certificado bloqueos reales de MySQL/SQL Server con SQLite. En despliegues de varios nodos, el almacén de caché debe compartir bloqueos entre ellos; el driver file sólo coordina procesos con ese mismo almacenamiento.

## Interfaz del consumidor

El modal de Productos y proveedores permite verificar proveedor, revisar la sugerencia de plazo, introducir notas y elegir estado/preferencia. provecompr sólo selecciona candidatos y nunca activa por sí mismo la preferencia. Tras completar la vista previa, el comprador confirma expresamente. El navegador ejecuta solicitudes cortas sucesivas, con opciones para pausar al finalizar el bloque actual y reanudar usando el UUID. Una respuesta 429 o pérdida de red detiene el envío; consultar estado antes de continuar evita repetir escrituras.

No requiere un trabajador de colas nuevo. No funciona con la página cerrada: el servidor sólo procesa el lote ya solicitado. El endpoint web requiere replenishment.manage, sesión, CSRF y titularidad de la operación; no concede permisos nuevos. La tabla de control debe instalarse explícitamente según [las modalidades de migración](migraciones.md).
