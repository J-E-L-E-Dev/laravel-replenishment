# Verificación de la Fase 1

Fecha: 7 de noviembre de 2026. Las pruebas se ejecutaron en Windows, sin servidores, navegadores ni migraciones.

Este documento conserva la evidencia histórica de la Fase 1. Al autorizar la Fase 2, el usuario aprobó expresamente el Junction instalado; la limitación de SymbolicLink descrita más abajo ya no bloquea el proyecto. La evidencia actual está en [verificación de la Fase 2](verificacion-fase-2.md).

## Matriz ejecutada

| PHP real | Laravel | Testbench | PHPUnit | Resultado |
| --- | --- | --- | --- | --- |
| 8.1.33 | 10.48.18 | 8.24.0 | 10.5.66 | 12 pruebas, 24 aserciones satisfactorias |
| 8.2.28 | 10.48.18 | 8.24.0 | 10.5.66 | 12 pruebas, 24 aserciones satisfactorias |
| 8.2.28 | 11.57.1 | 9.18.0 | 10.5.66 | 12 pruebas, 24 aserciones satisfactorias |
| 8.2.28 | 12.71.0 | 10.12.0 | 11.5.57 | 12 pruebas, 25 aserciones satisfactorias |

Se verificaron las tres versiones mayores con versiones concretas representativas; no se afirma haber ejecutado cada versión menor o cada versión posterior de PHP. Laravel 11 y 12 requieren PHP 8.2 como mínimo. El PHP 8.1.33 se descargó del archivo oficial de Windows PHP y se ejecutó desde un directorio temporal, sin cambiar el PHP del consumidor.

El entorno de desarrollo que queda instalado en el paquete corresponde a Laravel 12. El entorno Laravel 10/PHP 8.1 se aisló en un directorio temporal. Laravel 11 se verificó antes de resolver la matriz de Laravel 12. Ninguna de estas instalaciones actualizó las dependencias del consumidor.

Para repetir una versión mayor en un checkout de desarrollo del paquete, usar Composer con `--with="orchestra/testbench:^8.0"`, `^9.0` o `^10.0`, respectivamente, y ejecutar `composer test`. En Windows, invocar directamente `php ruta/al/composer.phar` evita que el lanzador BAT interprete el carácter `^`. Para reproducir exactamente una fila, fijar también sus versiones de Laravel y PHPUnit en el entorno aislado.

## Comprobaciones adicionales

- `composer validate --strict` satisfactorio en el paquete.
- Manifiesto y lock del consumidor validados satisfactoriamente.
- Los 18 archivos PHP de código, configuración y pruebas pasan el analizador sintáctico real de PHP 8.1.33.
- PHPDoc comprobado en los 15 componentes de producción y sus métodos propios; las dos clases de pruebas y todos sus métodos también están documentados en español.
- Resolución diferida, adaptadores ausentes e incompatibles, bindings incorrectos, preservación de configuración, publicación y diagnóstico cubiertos por pruebas.
- Precisión decimal, identificadores con ceros iniciales, fecha desconocida e inmutabilidad de DTO cubiertos por pruebas.

## Instalación del consumidor

Composer añadió únicamente `asisnet/laravel-replenishment:dev-main`: ninguna dependencia existente se actualizó ni eliminó. Los únicos archivos versionados modificados en consult-web son composer.json y composer.lock. El proveedor figura en bootstrap/cache/packages.php, se comprobó cargado al arrancar el kernel de consola y `replenishment:status` terminó correctamente con los seis adaptadores sin configurar.

El repositorio path utiliza exactamente `../../../mis-paquetes/laravel-replenishment` con `symlink: true`. La instalación existente es un **Junction** de Windows, con atributo ReparsePoint y destino verificado mediante realpath hacia el directorio original del paquete. No es una copia ni un enlace de tipo SymbolicLink.

Se intentó crear un SymbolicLink estricto, pero Windows respondió que requiere privilegios de administrador. Se restauró y verificó el Junction. Por tanto, el criterio literal de enlace simbólico estricto queda pendiente de un entorno con permisos suficientes; no se presenta como cumplido. Cuando dichos permisos estén disponibles, sustituir únicamente el vínculo, nunca su directorio de destino, y volver a verificar LinkType, realpath y Artisan.

## Autoload y limitaciones

La generación optimizada de autoload del consumidor quedó detenida durante el escaneo de la dependencia preexistente `aws/aws-sdk-php`. Se interrumpieron los procesos de esa comprobación y se completó `composer install --no-autoloader --no-scripts`. Después se generó el autoload estándar mediante la API de Composer, pasando `false` al parámetro de optimización de `AutoloadGenerator::dump` y desactivando sus scripts. El arranque y el descubrimiento posteriores fueron satisfactorios.

La opción original `optimize-autoloader: true` permanece intacta en composer.json. El autoload generado actualmente usa PSR-4 y los classmaps obligatorios, sin la optimización global; una regeneración optimizada posterior puede volver a tardar en el SDK de AWS. No se alteró ese paquete para resolver un asunto ajeno a esta integración.

Composer informó avisos de seguridad en las versiones de Laravel 10 y 11 usadas por las matrices históricas. No se actualizaron dependencias del consumidor ni se amplió el alcance a una auditoría de seguridad. La resolución final del entorno Laravel 12 no informó avisos.

La licencia se mantiene provisionalmente como `proprietary`, pendiente de decisión del titular antes de publicar. El nombre también es provisional; no existe publicación remota ni versión estable.

## Continuidad

La Fase 1 fue posteriormente aprobada con su Junction de Windows. La Fase 2 se autorizó de manera explícita y conserva ese mecanismo.

## Ajuste 7.2.3 — evidencia focalizada

En consult-web, PHP 8.2.28/Laravel 10/PHPUnit 10.5.29: `Adjustment723Test` pasa 3 pruebas y 64 aserciones sobre dos SQLite temporales. Cubre opciones filtradas antes de paginar, vigencia, contexto comercial, fechas, cantidades, diagnóstico incompleto, necesidad cero, redondeo, permisos y ausencia de persistencia/INSERT ERP. Sintaxis del controlador y prueba comprobada con PHP 8.1.

Frontend: `replenishment723.test.mjs` pasa 8 pruebas con componentes Vue reales en renderizador virtual. No sustituye revisión visual. Vite se compila en el consumidor. No se repitió matriz completa de Laravel ni suites históricas; no se ejecutaron bases reales, servidores o navegadores. MySQL/SQL Server aislados y revisión visual siguen pendientes.

## Fase 7.3 — evidencia ejecutada el 10 de noviembre de 2026

El consumidor ejecutó `ProposalWorkflowTest` con PHP 8.2.28, Laravel 10 y PHPUnit 10.5.29: **9 pruebas, 129 aserciones**, satisfactorias. Utiliza servicios del paquete y dos conexiones SQLite temporales: caso 010-055/P0861, captura desde simulación, ajuste, aprobación, origen inmutable, permisos directos incluso con Gate permisivo, conflictos, reintentos, rollback de auditoría, revisión histórica, filtros/paginación, claves externas, evolución/reversión y aislamiento.

El frontend ejecutó **4 pruebas** con componentes Vue reales mediante renderizado virtual: confirmación, evidencia firmada, estado desactualizado, UUID, visibilidad de aprobación, versión/huella, campos manuales y cadenas decimales. Vite 4.5.14 compiló **717 módulos en 29,75 segundos**, con aviso no bloqueante de Browserslist desactualizado. Sintaxis de 16 archivos PHP nuevos/modificados comprobada con PHP 8.1.

No se repitió la matriz histórica de Laravel. La simulación de conflictos por versión no equivale a una prueba de contención concurrente entre procesos en MySQL. Siguen pendientes instancia MySQL aislada autorizada, SQL Server aislado y revisión visual manual. No se ejecutaron migraciones, seeder, asignación de permisos ni escrituras sobre bases comerciales.

## Ajuste 7.3.1

Comprobado en consult-web: `Adjustment731Test`, 3 pruebas y 471 aserciones, PHP 8.2.28/Laravel 10/PHPUnit 10.5.29. Dos SQLite temporales, 1.005 relaciones, orden global/paginación, revisiones, desconocidos, filtros, creación y omisión de duplicados, consultas ERP por bloques y sólo SELECT. Frontend: 3 pruebas con renderizado virtual de componentes reales. Sintaxis de cuatro archivos PHP verificada con PHP 8.1. No se repitieron suites históricas; revisión visual y motores reales siguen pendientes.

Compilación del consumidor para 7.3.1: Vite 4.5.14, 718 módulos, resultado satisfactorio en 20,91 segundos; aviso no bloqueante de Browserslist desactualizado. Sin migraciones, escrituras ERP, cambios globales de seguridad ni Fase 7.4.


## Ajuste 7.3.2 — Generación por política

Se añade generación durable por bloques, historia anual/mensual/semanal, decisiones auditadas y costo opcional con semántica explícita. Consulte [la documentación](generacion-politica-732.md). La migración 000006 requiere ejecución manual posterior; no se migró consult-web. La verificación de costos, motores reales y concurrencia entre procesos permanece diferenciada de SQLite y de consultas SELECT.


### Evidencia ejecutada del ajuste 7.3.2

- Paquete: 48 pruebas focalizadas distintas y 419 aserciones, agrupadas en PolicyPlanning732Test, ReplenishmentPlannerTest, CommercialConfigurationTest, PublishedMigrationsTest y ForecastTest; PHP 8.2.28, Laravel 12, SQLite temporal.
- Consumidor: 21 pruebas distintas y 684 aserciones entre Adjustment732Test, Adjustment731Test y ProposalWorkflowTest; PHP 8.2.28, Laravel 10, dos SQLite temporales. Se ejecutaron grupos y repeticiones únicamente para verificar cambios o resolver fallos.
- Frontend: 11 pruebas entre 7.3, 7.3.1 y 7.3.2, con Vue real en renderizador virtual.
- Sintaxis del paquete: PHP 8.1 satisfactoria. Vite: 719 módulos, compilación final satisfactoria en 15,39 segundos. Aviso previo de caniuse-lite desactualizado, sin actualizar dependencias.
- No se ejecutaron migraciones reales ni se declara verificada escritura/concurrencia en MySQL o SQL Server. Las dos muestras SELECT y la conservación de propuestas 1/2 se documentan en el consumidor.
