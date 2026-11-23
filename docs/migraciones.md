# Modalidades de migración desde el ajuste 7.2.1

Elegir una modalidad por instalación. Ambas reutilizan las mismas definiciones de esquema y PersistenceConnection. Ninguna se registra automáticamente en el migrador global ni se ejecuta al abrir la interfaz.

## Instalaciones existentes con historial dedicado

consult-web ya fue migrado por el usuario y debe conservar su modalidad. No publicar envoltorios estándar para esta instalación. No borrar, renombrar ni rellenar automáticamente replenishment_migrations o migrations. No copiar las entradas internas de database/migrations al consumidor.

El ajuste incorpora una única migración evolutiva, 2026_11_10_000004_create_supplier_imports, que añade replenishment_supplier_imports. Esta tabla registra UUID, proveedor canónico, actor, estado, versión, cursor, opciones, conteos, vista previa y último lote confirmado. Las tablas comerciales existentes no cambian. La carga masiva devuelve error operativo si falta esta tabla; no la crea automáticamente.

El administrador debe revisar conexión, copia de seguridad y pendientes antes de aplicar expresamente:

~~~sh
php artisan replenishment:migrate
~~~

No se ejecutó ese comando en consult-web durante este ajuste. Continúa utilizando el registro dedicado en la conexión propia. La reversión explícita del último lote es replenishment:migrate --rollback; si ese lote sólo contiene la ampliación de importaciones, elimina únicamente su control. No usar la reversión como forma de cambiar de modalidad ni ejecutar un rollback de datos comerciales para verificar el paquete.

## Instalaciones nuevas con migrador estándar

Publicar voluntariamente:

~~~sh
php artisan vendor:publish --tag=replenishment-migrations
~~~

Los archivos se ubican en database/migrations/replenishment. Son envoltorios PublishedMigration, no copias de las entradas internas. Invocan las definiciones comunes después de comprobar que el repositorio de Laravel utiliza exactamente la conexión propia y que no existe historial dedicado.

Ejemplo MySQL, suponiendo una conexión Laravel ya declarada como replenishment_mysql con driver mysql:

~~~dotenv
REPLENISHMENT_DB_CONNECTION=replenishment_mysql
~~~

~~~sh
php artisan migrate --database=replenishment_mysql --path=database/migrations/replenishment
php artisan migrate:rollback --database=replenishment_mysql --path=database/migrations/replenishment
~~~

Ejemplo SQL Server, suponiendo una conexión ya declarada como replenishment_sqlserver con driver sqlsrv:

~~~dotenv
REPLENISHMENT_DB_CONNECTION=replenishment_sqlserver
~~~

~~~sh
php artisan migrate --database=replenishment_sqlserver --path=database/migrations/replenishment
php artisan migrate:rollback --database=replenishment_sqlserver --path=database/migrations/replenishment
~~~

replenishment_mysql y replenishment_sqlserver son nombres de conexiones Laravel, no nombres de driver. El paquete no crea conexiones. Si REPLENISHMENT_DB_CONNECTION está vacío, usar el nombre de database.default en --database. Laravel registra este historial en su tabla estándar migrations (o la configurada por la aplicación), en la misma conexión que las tablas del paquete. El comando con --path evita incluir migraciones ajenas; al revertir, el lote seleccionado debe corresponder a estos archivos.

Un migrate contra otra conexión falla antes del DDL del paquete. Laravel puede haber creado previamente su tabla vacía de registro en ese destino; no se registran migraciones de Replenishment allí. No se modifica globalmente Artisan ni se cambia la conexión predeterminada desde el paquete.

## Protección contra mezcla y conciliación

El envoltorio estándar rechaza incluso un historial dedicado vacío. El dedicado rechaza entradas estándar aplicadas y mantiene la protección frente a tablas previas sin historial conciliado. No existe conversión automática entre modalidades. Una instalación que requiera cambiar debe detener escrituras, respaldar, inventariar esquema e historiales y diseñar una conciliación manual verificable fuera del alcance de este ajuste. Las instalaciones existentes deben seguir usando su mecanismo actual.

Después de actualizar el paquete en una instalación estándar, revisar qué envoltorios nuevos faltan y publicar sin sobrescribir archivos modificados. No usar --force sin comparar los archivos. Los archivos históricos publicados mantienen referencia a las definiciones del paquete y deben desplegarse junto a una versión compatible.

## Evidencia y límites

Se ejecutaron migraciones estándar y reversión con Laravel 10 y 12 sobre SQLite aislado, además de la ampliación y reversión dedicadas, conexión incorrecta y rechazo de mezcla. No se repitió la matriz completa ni se amplió Composer. Laravel 11 no se volvió a ejecutar en este ajuste. MySQL y SQL Server reales de persistencia continúan pendientes de un destino aislado autorizado; ejemplos de configuración y lectura de metadatos ERP no equivalen a certificar su DDL.

Comprobación adicional: 24 archivos PHP nuevos o modificados de paquete y consumidor pasaron lint con PHP 8.1.33. La comprobación de presencia de PHPDoc no detectó métodos sin documentación en los servicios y componentes PHP inspeccionados.

## Ampliación de propuestas internas

La migración evolutiva `2026_11_10_000005_add_proposal_workflow.php` añade únicamente control y evidencia de Fase 7.3. Su envoltorio estándar optativo comparte `AddProposalWorkflow`. No cambia modalidad ni adopta cabeceras antiguas. El operador debe respaldar y confirmar la conexión propia antes de ejecutarla; no fue aplicada automáticamente al consumidor. La reversión elimina datos de auditoría de esta fase y sólo se probó en SQLite temporal. Véase [Propuestas](propuestas.md).

## Despliegue del ajuste 7.3.3

1. Confirmar la conexión Laravel de persistencia propia, el historial de migraciones que ya utiliza la instalación y una copia de seguridad verificable. Ensayar el despliegue en un destino aislado.
2. Con las evoluciones anteriores presentes, instalar en orden 000007 (publicaciones, días y unidades), 000008 (configuración de demanda) y 000009 (procesamiento durable). La etapa E utiliza estas estructuras. En una instalación dedicada corresponde al operador autorizado ejecutar `php artisan replenishment:migrate`. En modalidad publicada se aplican los envoltorios equivalentes 100007 a 100009 en su historial establecido. Mantener una sola modalidad.
3. Desplegar conjuntamente PHP y activos Vite del consumidor. Verificar con un usuario de consulta el acceso, los indicadores, la paginación, la versión del producto y la expansión del mes. La preparación de publicaciones requiere una confirmación de gestión en el flujo D2.
4. La columna ERP opcional `corte_de_estadisticas` tiene autorización, conexión e historial separados. Con el corte deshabilitado el adaptador omite dicha columna. La apertura del dashboard sólo informa de un despliegue propio pendiente.

Pendientes para una instalación comercial: verificación en MySQL y SQL Server autorizados, planes de consultas con sus índices y volúmenes reales, permisos efectivos y revisión visual en navegador.
