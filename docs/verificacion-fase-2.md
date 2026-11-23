# Verificación de persistencia — Fase 2

Fecha: 7 de noviembre de 2026. La Fase 1 fue aprobada, incluido el Junction de Windows. No se sustituyó ese vínculo ni se inició la Fase 3.

Esta evidencia corresponde a la implementación inicial de Fase 2. El Ajuste 2.1 sustituye el registro global de migraciones por el comando y registro dedicados descritos en integración, sin modificar las doce tablas de dominio.

## Matriz ejecutada

| PHP real | Laravel | Testbench | PHPUnit | Resultado |
| --- | --- | --- | --- | --- |
| 8.1.33 | 10.48.18 | 8.24.0 | 10.5.66 | 47 pruebas, 236 aserciones satisfactorias |
| 8.2.28 | 11.57.1 | 9.18.0 | 10.5.66 | 47 pruebas, 236 aserciones satisfactorias |
| 8.2.28 | 12.71.0 | 10.12.0 | 11.5.57 | 47 pruebas, 237 aserciones satisfactorias |

Las 12 pruebas de la fundación se mantienen y se añadieron 35 pruebas focalizadas de persistencia. Cada caso de persistencia crea una conexión SQLite en memoria con claves foráneas activas. Los datos auxiliares se construyen con helpers documentados; no se añadieron factories ni dependencias de generación de datos innecesarias.

El paquete conserva instalado su entorno de desarrollo Laravel 12. Laravel 10/PHP 8.1 y Laravel 11/PHP 8.2 se ejecutaron con dependencias en directorios temporales independientes, cargando el código original mediante path. No se cambiaron el PHP ni el framework del consumidor.

## Cobertura ejecutada

- Creación de las doce tablas mediante las migraciones registradas por el proveedor y reversión en orden inverso, preservando una tabla ajena.
- Aislamiento entre la conexión del paquete y la predeterminada de la aplicación.
- Claves foráneas reales, unicidad comercial, proveedores y presentaciones múltiples, revisiones explícitas.
- Persistencia decimal exacta de 24 dígitos, rechazo de float, exceso de escala y desbordamiento.
- Reglas de conversión, mínimos y múltiplos; identificadores opacos; separación por producto y ubicación.
- NULL para datos desconocidos, sin métricas ficticias de proveedores ni presentaciones inventadas.
- Instantáneas históricas, imposibilidad de sobrescribir resultados o su contexto y restricciones de borrado sin cascadas.
- Ajustes editables de propuestas, coherencia de producto/proveedor, costos y cantidades sugeridas conservadas.
- Estados de ejecuciones y propuestas, ventanas crecientes, rangos de períodos estacionales y múltiples versiones de pronósticos.
- Configuración explícita de políticas, activación condicionada a parámetros completos, JSON textual y fechas UTC con microsegundos.
- Compilación del DDL SQL Server de creación y reversión, tipos decimales, nvarchar(max), ausencia de cascadas y nombres menores de 128 caracteres.

## Instalación y efectos

Se añadió Illuminate Database como dependencia directa del paquete porque los modelos y migraciones lo utilizan. En consult-web ya lo proporciona Laravel Framework: la actualización Composer afectó únicamente a los metadatos de asisnet/laravel-replenishment, sin instalar, actualizar ni eliminar otras dependencias. Se omitieron autoload optimizado y scripts, conservando la integración PSR-4 existente.

El Junction permanece apuntando al paquete original. Artisan carga el proveedor y ejecuta el diagnóstico correctamente. No se ejecutó migrate, rollback ni una consulta a tablas ERP sobre consult-web. No se cambió su configuración comercial ni se publicaron archivos. La documentación y todos los componentes PHP propios tienen comentarios y PHPDoc en español.

## Pendientes y límites

No se dispone en el alcance de una instancia SQL Server 2017 temporal y autorizada. La compilación de su gramática es una revisión técnica, no una prueba contra el motor real. Quedan pendientes la ejecución DDL, ida y vuelta de decimales/JSON/fechas, restricciones, intercalación y rollback en dicha instancia. Otros motores no se declaran verificados.

La inmutabilidad y validación de dominios se aplican a escrituras individuales Eloquent con eventos; query builder, upsert, SQL directo y métodos que silencian eventos no constituyen API de escritura de dominio. La base sí impone las claves foráneas, NOT NULL y unicidad declaradas. La representación textual decimal de SQLite exige comprobar aparte cualquier futura aritmética SQL en el motor de producción.

Composer informó 63 avisos de seguridad en 18 dependencias existentes del consumidor y cinco avisos en una dependencia de la matriz histórica Laravel 11. No se hizo una actualización global ni una auditoría fuera del alcance. La validación funcional de esta fase no certifica seguridad de las dependencias heredadas.

No quedan decisiones comerciales bloqueantes: parámetros desconocidos permanecen NULL, las políticas empiezan inactivas y las conversiones son explícitas. La licencia y publicación remota siguen pendientes según lo registrado en la Fase 1.

La persistencia está preparada para la Fase 3, que requiere autorización y no fue iniciada.
