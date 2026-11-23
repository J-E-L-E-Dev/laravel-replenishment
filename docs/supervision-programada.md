# Supervisión de inventario y propuestas manuales

## Entrega 7.4.2-A

El comprador elige un proveedor vinculado, verifica su identidad y selecciona expresamente una política. La generación congela la selección y conserva la política, las hipótesis, los resultados y las decisiones de esa ejecución. Una nueva configuración se aplica a operaciones posteriores. La propuesta sigue requiriendo revisión y guardado explícitos.

En consult-web, el selector parte de las relaciones activas de `ProductSupplier` en la conexión propia. Busca un fragmento literal de código o razón social, devuelve hasta veinte proveedores por página y obtiene nombres del maestro en lotes de hasta cien códigos. Cada petición revisa como máximo quinientos códigos. Una búsqueda poco frecuente puede requerir continuar a la página siguiente incluso si el bloque actual carece de coincidencias. Los conteos agrupados se consultan para la página visible. No hay una instantánea distribuida de ambos motores.

Cada proveedor muestra productos vinculados, productos con una sola presentación vigente, pendientes sin presentación y, cuando corresponde, productos con varias opciones. Sólo cuenta la última revisión activa por relación y unidad de compra activa. La identidad sin nombre sigue visible. Los contadores del proveedor describen su configuración. Desde 7.4.2-A.1, la tabla de generación y la congelación exigen una relación activa y una sola presentación comercial vigente e íntegra. El factor y el múltiplo deben ser positivos y el mínimo no negativo. Los productos con varias presentaciones quedan pendientes de resolución; la elección del comprador al editar una decisión posterior sigue disponible. La verificación exacta también permite introducir un código directamente.

La política reúne los parámetros generales, el horizonte, la estrategia, los escenarios y las opciones avanzadas. El comprador puede alternar estrategias antes de guardar conservando la ventana y los bloques de la edición abierta. Los pesos aplicables deben sumar 100 %. La continuidad asumida requiere consentimiento expreso. El nivel de servicio es un objetivo comercial.

En la generación, el comprador autoriza por separado la estimación histórica exploratoria y la hipótesis de cero entradas desconocidas. Puede dejar desconocida la seguridad, fijarla en cero o introducir unidades adicionales. El presupuesto sirve para revisar importes. La preparación estadística mantiene las tres modalidades de D2. El detalle diferencia el consumo durante el plazo, durante la cobertura posterior y durante toda la protección.

Las cantidades del resumen se presentan con coma y hasta dos decimales, y las tasas comparativas con hasta cuatro. Una magnitud muy pequeña conserva una indicación de su signo. Los factores y las cantidades editables conservan todas las cifras significativas. Los importes mantienen su moneda declarada, los porcentajes su escala y los valores desconocidos su condición. La evidencia técnica conserva el valor de origen.

## Diseño para etapas posteriores

La supervisión será un flujo de consulta periódica separado de la generación manual. Cada programación identificará proveedor, política, selección congelada o universo dinámico de productos elegibles, frecuencia, día y hora locales, zona horaria, inicio, fin opcional, estado activo o pausado, destinatarios, condiciones de alerta e historial de ejecuciones. Cada ejecución conservará el universo resuelto, la revisión de política, el corte operativo y la evidencia consultada.

Laravel Scheduler detectará las programaciones vencidas y despachará trabajo en Laravel Queue. Un worker dedicado podrá procesar la cola con `php artisan queue:work --queue=replenishment`. Una clave por programación e instante previsto evitará duplicados, con bloqueo, cursor durable, bloques acotados y reintentos idempotentes. El historial asociará los resultados, las incidencias y el envío al identificador de ejecución.

Las condiciones previstas incluyen agotamiento observado, existencia bajo mínimo, punto de pedido y necesidad proyectada. Se distinguirán existencia, disponibilidad, fechas y cantidades desconocidas. Se reutilizarán los servicios de reposición y los transportes de correo del consumidor. Se prevé **un correo consolidado por programación y ejecución** con sus resultados y un enlace de revisión. La propuesta interna y su aprobación seguirán siendo decisiones humanas.

Para el futuro entorno de desarrollo se prevé `REPLENISHMENT_ALERT_TEST_RECIPIENTS=edwinylil3@gmail.com`. Producción utilizará los destinatarios autorizados de cada programación. Esta etapa sólo documenta la variable.

## Parámetros del maestro de inventario

Antes de incorporar `I00001.minimo`, `maximo`, `diasmin`, `diasmax`, `diasptoped` y `ptopedido` habrá que verificar significado, unidad, ámbito, vigencia y tratamiento de nulos con el responsable del ERP. Se propone un contrato de lectura específico para los parámetros por producto y destino, con un adaptador OrbisNET en el consumidor. `ProductSource` seguirá resolviendo identidades y el contrato de inventario seguirá suministrando saldos. La conexión de persistencia del paquete conservará su independencia.

En una entrega posterior, la revisión podrá comparar el valor leído con una sugerencia y registrar si el responsable conserva, acepta o personaliza el valor. Una eventual escritura requerirá un contrato de actualización separado, permiso específico, comprobación de la versión del maestro y auditoría de valores anterior y nuevo. Antes de definir fórmulas habrá que distinguir mínimos, máximos, punto de pedido y protección ya contabilizada. El máximo no acredita stock de seguridad.

Esta entrega comprende interfaz y lecturas de proveedores. La verificación de semántica ERP, las escrituras autorizadas, las programaciones, los workers y el envío de correo quedan para fases posteriores.

