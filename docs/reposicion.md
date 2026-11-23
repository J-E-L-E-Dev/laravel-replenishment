# Reposición y simulación de compras — Fase 6.2

## Alcance

ReplenishmentQuery coordina AvailabilityQuery, ForecastQuery, SupplierLeadTimeQuery y PurchaseSource. ReplenishmentCalculator aporta aritmética exacta, agregación de pendientes y ventanas mensuales. ReplenishmentPlanner combina esos resultados sin acceder al ERP. ReplenishmentStore añade una ejecución y un resultado propios atómicamente. ReplenishmentOptions y ReplenishmentPlanData conservan parámetros y auditoría inmutables.

No se generan órdenes oficiales, propuestas aprobables, interfaces Vue ni escrituras comerciales. Los ocho pilotos anteriores conservan cuatro pronósticos exploratorios y cuatro insuficientes: ninguno usable. Tampoco se han certificado plazos históricos reales, pendientes completos ni demanda perdida.

## Tiempo, unidades y demanda

La evaluación usa medianoche civil de America/Caracas. Se consulta disponibilidad actual y se verifica que observedAt corresponda a ese día; no se reutiliza una observación antigua para afirmar un saldo futuro. Las lecturas independientes no constituyen una instantánea transaccional del ERP.

El horizonte de protección empieza en la fecha objetivo y contiene de uno a seis meses **completos**. Para calcular cantidades, la fecha objetivo debe ser el primer día del mes. Se puede diagnosticar una fecha intermedia y obtener una fecha orientativa de pedido, pero sus cantidades permanecen insuficientes por falta de resolución temporal; no se reparte demanda mensual por días.

El pronóstico conserva producto, destino, unidad base, corte, opciones, métodos y calidad por mes. Su corte no puede ser posterior a la evaluación. Sólo meses contiguos completos con cantidades conocidas participan. usable puede formar parte de una recomendación si todos los demás insumos son suficientes; exploratory fuerza simulation; insufficient no produce cantidad automática.

--monthly-demand declara una cantidad **por cada mes completo** del horizonte, no un total para dividir. Requiere --base-unit igual a la unidad de inventario. Esta hipótesis no se aplica automáticamente al tramo anterior a la recepción. Si se usa demanda manual, la consulta omite ForecastQuery y exige --consumption-before para el tramo previo, salvo que evaluación y recepción coincidan.

El consumo entre evaluación y recepción puede sumarse del pronóstico sólo cuando ambos límites son mensuales, todos los meses están cubiertos y sus unidades son comparables. Si coinciden las fechas, el intervalo vacío aporta cero. En una evaluación a mitad de mes se necesita --consumption-before explícito para simular el consumo total restante; nunca se anualiza ni se interpola el pronóstico. Una unidad manual distinta produce error.

La CLI usa corte de pronóstico al inicio del mes actual y solicita hasta el fin del horizonte protegido, con máximo seis meses del motor aprobado. Un horizonte que exceda esa cobertura conserva demanda desconocida. --history-from permite limitar el entrenamiento; por defecto son tres años anteriores al corte. No se altera la selección ni la calidad de Fase 5.2.

## Pendientes certificados

PurchaseSource devuelve cantidades base únicas para el producto/destino, incluso de otros proveedores: todas pueden afectar la posición del mismo inventario. Se completa toda la iteración antes de publicar. Una lista vacía certifica ausencia. Una excepción, identidad contradictoria, ámbito inválido o más de 5000 filas leídas invalida el lote. Repeticiones idénticas de una línea se cuentan una sola vez.

Se separan:

- certified_positive: cantidad positiva conocida.
- certified_absent: cero confirmado por lectura completa.
- unknown: no se pudo certificar el lote; total y calendario quedan NULL.
- assumed_absent: únicamente si unknown y se activó --assume-no-incoming. Es hipótesis de simulación, nunca verified_incoming persistido.

La posición total puede conocerse aunque falte el calendario. La proyección a fecha objetivo incluye sólo entradas previstas entre evaluación y objetivo, ambos días incluidos. Entradas posteriores no se anticipan. Una fecha ausente o vencida impide afirmar el subtotal a tiempo: una entrega atrasada no se supone llegada. La hipótesis de cero **no borra** entradas positivas certificadas cuyo calendario sea desconocido. Las fechas previstas tampoco son garantías de recepción efectiva.

## Fórmulas

Disponibilidad se toma íntegra de Fase 3.1:

```text
available = on_hand - fixed_reserved - manual_reserved - active_quotation_reserved
position = available + total_certified_incoming
projected_at_receipt = available - consumption_before_receipt + incoming_by_target
safety = seguridad explícita del escenario o calibración externa acreditada
minimum_stock = safety
maximum_stock = target_stock = horizon_demand + safety
required_base_units = max(0, target_stock - projected_at_receipt)
```

No se descuentan reservas nuevamente ni se truncan saldos negativos. Cualquier operando desconocido propaga NULL a la fórmula dependiente. La necesidad puede aparecer como cálculo parcial en un diagnóstico insuficiente —por ejemplo, sin presentación—; no equivale a una cantidad autorizada para comprar.

minimum_stock representa el piso de protección definido por la seguridad del escenario o evidencia; maximum_stock es el objetivo de la ventana elegida. No son mínimos/máximos del ERP y no se escribe I00001.minimo/maximo.

reorder_point sólo suma demanda durante **todo el plazo** y seguridad si ese tramo está cubierto por meses completos, o coincide exactamente con el tramo de consumo previo explícito. Si su demanda es exploratoria, el resultado también queda simulado. Un plazo de siete días sobre pronósticos mensuales no permite un punto de pedido diario. projected_coverage_days permanece NULL: conocer cierres mensuales no acredita el instante de agotamiento ni disponibilidad intramensual.

## Seguridad y evidencia externa

El nivel de servicio de Policy no se convierte en unidades. Sin seguridad numérica justificada o explícita, safety_stock permanece NULL y el cálculo es insuficiente.

--safety-stock, incluido cero, siempre identifica una simulación. Para integraciones que ya dispongan de calibración real se ofrece SafetyStockEvidenceData: producto, destino, unidad, cantidad, período exacto desde evaluación hasta fin de protección y referencia auditable a distribución o errores calibrados. El núcleo valida coherencia y alcance; **no autentica ni genera la calibración**. Una referencia textual no demuestra evidencia. La CLI, OrbisNET y los pronósticos actuales no producen este DTO. Su uso se probó sólo con evidencia ficticia. No hay stock de seguridad estadístico real certificado en esta fase.

## Plazos y fechas

Se reutiliza la precedencia de Fase 6.1: manual activo, histórico certificado utilizable, política explícita y desconocido. Se conservan fuente, calidad, medida, ámbito y advertencias. Un plazo hasta primera recepción no se usa como plazo hasta abastecimiento completo.

--lead-time permite días enteros de escenario, incluido cero. Omite la consulta del plazo original para ese diagnóstico, lo identifica como no consultado y no modifica ProductSupplier. Se conserva por separado lead_time_original y la resolución de escenario. Un plazo desconocido deja fecha orientativa NULL.

```text
indicative_order_date = target_receipt_date - ceil(selected_lead_time_days)
```

La conversión hacia arriba de un promedio fraccionario a días civiles es explícita en lead_time_calendar_days, sin margen comercial adicional. Una fecha ya pasada activa planned_lead_time_insufficient y una advertencia. La fecha objetivo es un objetivo elegido, no recepción garantizada; la resta no es una optimización de inventario.

## Presentaciones

Se exige identidad explícita de SupplierPresentation. La consulta verifica relación producto-proveedor activa, catálogo comercial activo, presentación activa y revisión más reciente de la misma relación/unidad de compra. No se elige silenciosamente otra presentación ni otra revisión. El factor ya configurado representa unidades base de ese producto; no se deduce de C02091.unidad ni de compras antiguas.

```text
si need = 0: packages = 0
si need > 0:
  packages = ceil(max(need / factor, minimum_packages) / package_multiple)
             * package_multiple
base_units = packages * factor
rounding_excess = base_units - need
```

La implementación evita redondear primero need/factor: divide por factor*múltiplo con CEILING y verifica después el mínimo. Admite factores y múltiplos decimales explícitos. Un mínimo no múltiplo se eleva al siguiente múltiplo. No se impone mínimo cuando la necesidad es cero. Brick Math conserva toda la precisión durante operaciones.

## Estados

| Estado | Regla |
|---|---|
| recommended | Insumos necesarios completos, sin hipótesis ni categorías exploratorias; necesidad positiva |
| simulation | Cálculo completo con alguna hipótesis manual, pendiente supuesto o pronóstico exploratorio; también si necesidad cero |
| not_required | Necesidad cero con insumos completos y sin hipótesis |
| insufficient_data | Falta algún insumo indispensable, calendario, presentación vigente o cobertura temporal; conserva cálculos parciales y motivos |
| error | Fallo técnico indispensable o parámetros inválidos; la CLI emite JSON sin publicar resultados parciales |

La falta de datos tiene precedencia sobre simulation: un escenario incompleto continúa siendo insufficient_data y mantiene sus hipótesis en assumptions. La CLI actual no puede fabricar recommended porque no dispone de seguridad calibrada; los datos reales tampoco cubren los otros requisitos confirmados.

## Persistencia

--persist añade replenishment_runs y replenishment_results mediante PersistenceConnection/REPLENISHMENT_DB_CONNECTION. Estado simulation se admite en el modelo existente, cuya columna ya es texto: no hace falta migración. Se guarda auditoría completa en parameters_json y explanation, con cantidades, reservas consultadas, pronóstico, plazo original/seleccionado, entradas, hipótesis, fórmulas, fechas y versión replenishment-plan-1.0.

La ejecución se crea completada junto a su resultado dentro de una única transacción; el estado comercial del resultado sigue separado. Un fallo posterior al INSERT del resultado revierte también la ejecución. Nuevas publicaciones añaden históricos, sin actualizar los anteriores. ReplenishmentResult conserva la inmutabilidad existente.

Se comprueba de nuevo la revisión antes de escribir. Si cambió su configuración o vigencia, se exige repetir el diagnóstico. El modelo captura su fotografía y las revisiones futuras no alteran resultados antiguos. Sin presentación puede persistirse un diagnóstico insuficiente, con fotografía NULL.

Las columnas existentes tienen seis decimales: reciben HALF_UP, declarado como storage_rounding. **La auditoría conserva cantidades exactas**, incluidos doce decimales del pronóstico. Para reconstruir operaciones y redondeo de paquetes se debe leer esa auditoría, no recalcular a partir de columnas resumidas. verified_incoming permanece NULL cuando cero es supuesto. expected_receipt_date conserva el objetivo elegido, no una confirmación de entrega.

No hay migraciones nuevas ni reales, nuevas conexiones ni dependencias. No se publicaron resultados ni configuraciones de prueba en bases reales. La comprobación de persistencia usa SQLite temporal.

## CLI

```sh
php artisan replenishment:plan PRODUCTO PROVEEDOR --target=2026-12-01 --horizon=2 --location=orbis-global --presentation=ID
```

Simulación enteramente explícita, donde ID es una revisión propia previamente configurada:

```sh
php artisan replenishment:plan PRODUCTO PROVEEDOR --target=2026-12-01 --horizon=1 --monthly-demand=20 --consumption-before=5 --safety-stock=0 --lead-time=7 --base-unit=UND --assume-no-incoming --presentation=ID
```

Añadir --persist sólo para guardar el diagnóstico en tablas propias instaladas. La consulta no migra, no crea relaciones ni presentaciones, y no aprueba compras. La fecha de evaluación es actual; no hay --as-of que simule inventario histórico con reservas actuales. --target predetermina el primer día del mes siguiente.

JSON compacto: quantities, formulas, dates, forecast, lead_time, incoming, presentation, assumptions, uncertainty y warnings. No lista líneas comerciales entrantes ni entrenamiento completo. Códigos: 0 para cálculo completo (incluye simulation/not_required), 2 para insufficient_data, 1 para error. Ningún código autoriza una compra.

## Simulaciones y pruebas

Dos ejemplos sintéticos reproducibles:

| Escenario | Disponible | Consumo previo | Demanda horizonte | Seguridad | Necesidad base | Presentación | Resultado |
|---|---:|---:|---:|---:|---:|---|---|
| Manual y pendiente cero supuesto | 10 | 5 | 20 | 0 explícita | 15 | Factor 2,5; mínimo 2; múltiplo 2 | 6 paquetes, 15 unidades, excedente 0; simulation |
| Demanda y consumo manuales, seguridad ficticia acreditada | 10 | 4 | 12,5 | 5 | 11,5 | Factor 6; mínimo 2; múltiplo 2 | 2 paquetes, 12 unidades, excedente 0,5; simulation |

También se verificó un recommended **exclusivamente sintético**: disponible 10, consumo previo 20, demanda 20 y seguridad calibrada ficticia 5 producen necesidad 35, seis paquetes de seis unidades y excedente 1. No representa evidencia comercial real.

Paquete: 15 pruebas, 100 aserciones, PHP 8.2.28, PHPUnit 11.5.57 y Laravel 12. Consumidor: filtro Planning, 2 pruebas, 42 aserciones, PHPUnit 10.5.29 y Laravel 10. Total 17 pruebas y 142 aserciones satisfactorias. Incluyen reservas, negativos/desconocidos, categorías, manuales, entradas por fecha y duplicados, intervalos parciales/cambio de año, seguridad, límites de unidades, revisiones, mínimos/múltiplos/factores decimales, cero, precisión de auditoría, estados, transacciones y ausencia de escrituras ERP.

La sintaxis PHP 8.1 se comprueba por lint. No se repitió la matriz Laravel ni se ampliaron restricciones Composer. Las conexiones llamadas mysql/sqlsrv en pruebas usan SQLite independientes: no certifican persistencia real MySQL/SQL Server.

## Contraste real acotado — 09-11-2026

Se seleccionó sólo el primer producto de las órdenes ya investigadas 800/00001330 y 800/00001290, usando su proveedor original. Cuatro diagnósticos de escenario y un diagnóstico adicional con entrenamiento de un mes generaron **37 SELECT y cero escrituras ERP**. No se consultó todo el catálogo ni se repitieron los ocho pilotos.

En ambos productos, disponibilidad conocida y pendiente unknown dejaron necesidad NULL. Con demanda 20/mes, consumo previo 5, seguridad 0 y plazo 7 expresamente simulados, al suponer cero entrante pudo calcularse una necesidad base, pero no paquetes: faltaba presentación propia. Ambos resultados siguieron siendo insufficient_data; no se inventaron presentaciones ni se insertó configuración real.

El caso adicional usó ForecastQuery sobre [2026-10-01, 2026-11-01); su demanda fue insufficient y la necesidad NULL. No sustituye ni cambia las conclusiones de los ocho pilotos históricos. No hubo recomendaciones reales validadas. Los archivos temporales de contraste se retiraron.

La siguiente fase no está iniciada. Permanecen pendientes la evidencia comercial y temporal insuficiente, calibración real de seguridad, presentaciones explícitas y validación de persistencia en motores reales aislados.
