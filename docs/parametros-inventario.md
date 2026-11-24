# Revisión de parámetros de inventario (7.4.2-B)

Desde una generación con líneas calculadas, **Revisar parámetros** abre la comparación del proveedor. Incluye líneas con necesidad de compra cero y generaciones con propuesta guardada. La revisión utiliza la unidad de inventario y el resultado efectivo que quedó registrado en cada línea. La disponibilidad que se muestra proviene de ese análisis. Abrir la página no reconstruye estadísticas ni ejecuta el planificador.

## Interpretación y precisión

Los niveles pertenecen a la política de reposición del paquete. El plazo efectivo L se expresa en días civiles enteros, aplicando el mismo techo del planificador al plazo fraccionario. C es la cobertura objetivo de la política congelada, S es la seguridad adicional explícita en unidades y D(h) es la demanda acumulada desde el comienzo del análisis durante h días.

| Parámetro del paquete | Destino en el consumidor | Sugerencia | Representación del destino |
|---|---|---|---|
| minimum | minimo | D(C) + S, protección mínima | NUMERIC(18,2) |
| maximum | maximo | D(L + C) + S, nivel objetivo | NUMERIC(18,2) |
| minimum_days | diasmin | C | NUMERIC(4,0) |
| maximum_days | diasmax | L + C | NUMERIC(4,0) |
| reorder_days | DIASPTOPED | L | NUMERIC(4,0) |
| reorder_point | PTOPEDIDO | D(L) + S, umbral de disponibilidad para evaluar reposición | NUMERIC(9,0) |

Para D(L) y D(L + C) se aprovechan los tramos confirmados antes y después de la recepción. Para D(C) se toma el prefijo desde la fecha del análisis. Una tasa racional exacta permite proyectar ese prefijo con la estrategia elegida, incluidos promedio reciente, ponderado o tasa manual. Con pronósticos se suman los meses completos guardados que cubran exactamente el prefijo. Si acaba a mitad de un mes y el plan carece de evidencia diaria compatible, la sugerencia queda desconocida. Un importe de compra, la demanda del tramo posterior, las entradas o el saldo proyectado no sustituyen esa evidencia.

La seguridad se añade una vez a cada nivel. Un cero explícito permite calcularlo. Con seguridad desconocida se conservan los horizontes respaldados y las cantidades quedan sin sugerencia. Se conserva la procedencia manual o exploratoria. El objetivo máximo y el umbral pueden orientar supervisiones posteriores; por sí mismos no generan una compra.

El motor usa racionales y decimales exactos. Aplica techo a la precisión de inventario, con un máximo de dos decimales, y techo entero al punto de pedido. El consumidor declara la precisión comercial por unidad mediante `replenishment-orbis.inventory_unit_scales`. La configuración inicial trata `UND` como indivisible. Para una unidad verificada a centésimas se registra su código exacto con escala 2, y a décimas con escala 1. Cuando se desconoce esa precisión se pueden representar cantidades enteras, mientras que una protección fraccionaria queda pendiente. Los factores de caja y los múltiplos de compra pertenecen a la propuesta y se excluyen de estas fórmulas.

Con siete unidades en 365 días, promedio general, L = C = 90 y S = 0, el mínimo y el punto proyectan 630/365 unidades y el máximo 1260/365. Para UND resultan **2, 4 y 2**, respectivamente. Con centésimas autorizadas resultan **1,73, 3,46 y 2**. Un máximo inferior al punto de pedido tras el techo entero se comunica como conflicto.

## Comparación y decisiones

El consumidor aporta `InventoryParameterSource`, que retorna un mapa de `InventoryParameterData`. Su adaptador consulta por código en bloques de hasta cien productos mediante la conexión Laravel definida en `REPLENISHMENT_ERP_CONNECTION`. Sólo selecciona código, descripción, unidad y seis parámetros de `dbo.I00001`. Convierte los numéricos a texto en SQL para preservar su precisión desde el driver. La lectura conserva NULL, cero y negativos heredados. La ausencia del producto o un cambio de unidad impiden aprobar cambios.

La pantalla ordena por código dentro del proveedor de la generación y presenta 25 productos por página. Permite filtrar por código, descripción, sugerencias disponibles, evidencia parcial o insuficiente, coincidencias completas y diferencias. Un producto con sugerencias parciales puede aparecer en más de un filtro. Las búsquedas que comparan datos del maestro y evidencia propia recorren el conjunto en bloques; su coste depende del tamaño de la generación.

Cada campo empieza en **Conservar actual**. **Utilizar sugerido** recupera el cálculo del servidor. **Introducir valor personalizado** conserva la autoría humana y exige una cantidad decimal representable. La comparación numérica respeta NULL y cero. Antes de aprobar diferencias se valida el conjunto decidido, incluidos mínimos, máximos y punto de pedido. Conservar una anomalía puede registrarse como no aplicable. El diálogo solicita confirmación y admite una justificación. Las acciones masivas abarcan las filas pendientes de la página, omiten las revisiones previas y, al aceptar sugerencias, omiten conjuntos incompatibles. Para cambiar una decisión anterior hay que abrir su detalle y confirmar una nueva revisión. Si la línea fue recalculada, la página sigue mostrando la fotografía guardada. **Comparar el análisis actualizado** permite revisar los nuevos operandos, con elecciones iniciales de conservación y una nueva lectura del maestro. La siguiente confirmación añade una versión y mantiene la anterior en el historial.

## Persistencia y concurrencia

La migración propia 000010 añade únicamente `replenishment_parameter_reviews`. Las decisiones de compra existentes tienen otra finalidad. Cada versión nueva referencia la línea de generación y conserva fotografía del maestro, parámetros sugeridos, demanda por horizonte, señal, método, calidad, publicación estadística cuando existe, plazo, política, seguridad, valores decididos, modalidad, diferencias, actor y fechas. La pantalla permite consultar versiones anteriores. Pendiente corresponde a una línea calculada sin revisión. Los estados persistidos son revisado sin cambios, cambios aprobados para aplicación futura y no aplicable.

La instalación utiliza el mecanismo de migración ya elegido para la conexión propia del paquete. La pantalla nunca migra automáticamente. La prueba de reversión se ejecuta en SQLite temporal.

La lectura ERP ocurre antes de la transacción propia. El servidor reconstruye la comparación pendiente y contrasta su huella con la presentada. Si ya existe una revisión, la muestra desde su fotografía; antes de aprobar diferencias se revalidan los seis valores, la unidad y la precisión vigentes. Dentro de una transacción propia, se bloquean las líneas en orden de identidad, se comprueba la versión de revisión y la huella del resultado de generación y se añaden nuevas versiones. Un conflicto devuelve HTTP 409 y revierte el lote. El ERP y la base propia carecen de una instantánea transaccional compartida.

Para una aplicación futura quedarán disponibles la clave exacta del producto, la fotografía esperada y los campos aprobados con diferencia efectiva. La fase C deberá releer y comparar en el momento de aplicar, controlar permisos específicos, actuar por producto con transacciones cortas, operaciones parametrizadas y reintentos limitados, y registrar su resultado. El supervisor posterior podrá consultar estos niveles y la disponibilidad normalizada. Esta fase termina en la decisión interna.
