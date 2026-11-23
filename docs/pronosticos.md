# Pronósticos mensuales de ventas observables — Fase 5.2

## Alcance y arquitectura

`DemandForecastEngine` recibe `ObservedDemandData`, `ForecastOptions` y la política de estacionalidad aprobada. `ForecastTraining` prepara períodos y cortes; `ForecastBacktester` separa selección y validación; `ForecastEvaluator` calcula errores exactos. `ForecastModelInterface` define métodos puros, implementados por `StatisticalForecastModel`. Los DTO inmutables `ForecastPeriodData`, `ForecastEvaluationData` y `ForecastData` conservan evidencia y resultados.

`ForecastQuery` reutiliza `ObservedDemandQuery` o `ObservedDemandStore`. El núcleo no consulta tablas ERP ni duplica normalización comercial. `ForecastStore` reutiliza `DemandForecast` y auditoría de `ProductMetric`; no se crean migraciones ni dependencias.

La señal es `verified_gross_sales`: FAC verificada en el adaptador Orbis. NCC, NDC y neto permanecen como información complementaria en los meses originales. No se pronostica demanda total de mercado ni consumo neto definitivo de inventario. Tampoco se supone que una devolución vuelva a estar disponible para vender. Esa decisión corresponde a una fase posterior de abastecimiento.

## Corte y datos utilizables

El intervalo de entrenamiento es `[from, until)`, con ambos límites a medianoche del primer día del mes operativo. Se rechazan límites parciales y cortes posteriores al inicio del mes en curso. `until` es el origen explícito y el primer mes pronosticado: no se utiliza información de ese mes ni posterior para ajustar, seleccionar o validar el pronóstico emitido desde él.

Si el corte está en el pasado, el resultado es una reconstrucción de un pronóstico desde esa fecha, aunque se calcule hoy. El horizonte uno corresponde al mes completo que comienza en el corte; nunca es el resto del mes desde la fecha de ejecución. No se distribuyen cantidades en días ni se prorratean meses incompletos.

Se conservan los meses comerciales desconocidos como NULL. El ajuste utiliza el tramo consecutivo conocido que termina exactamente en el origen, sin saltar huecos ni cambios de unidad. Los métodos recientes usan como máximo `window` meses de ese tramo. Los datos para el factor estacional pueden abarcar más años, pero siempre pertenecen al entrenamiento anterior al origen. Un saldo físico desconocido no modifica las ventas verificadas.

La ausencia de eventos después de una lectura completa conserva ceros observados; no demuestra inexistencia de demanda potencial, antigüedad comercial del producto ni cobertura histórica transaccional perfecta.

## Métodos

Todos trabajan con aritmética decimal. Las tasas y operaciones recurrentes se redondean explícitamente a doce posiciones HALF_UP; no se usan float ni distribuciones diarias ficticias.

1. **Media histórica (`mean`):** suma de FAC dividida por suma de días reales de los meses de ajuste. Pronóstico del objetivo = tasa × días reales del mes futuro.
2. **Media ponderada (`weighted`):** suma de `FAC_i × i^p` dividida por suma de `días_i × i^p`, con posiciones antiguas a recientes `i=1..n` y exponente explícito `p`. Cada mes aparece una sola vez, sin ventanas acumuladas superpuestas.
3. **Suavizamiento exponencial simple (`ses_alpha`):** sobre tasas diarias mensuales, `nivel_t = alpha × tasa_t + (1-alpha) × nivel_anterior`, inicializado con la primera tasa de la ventana. Cada alpha es un candidato fijo; no se ajusta con el objetivo que se evalúa. El nivel final se multiplica por días futuros.
4. **Tendencia amortiguada (`trend`):** regresión lineal de tasas contra posición mensual, con al menos seis meses. Se extrapola desde el último valor ajustado usando `pendiente × suma(phi^j)` para `j=1..h`. La tasa queda entre cero y `maximum_trend_factor × max(tasa_media, última_tasa)`. Se auditan pendiente, amortiguación, límite y recorte. No se interpreta crecimiento general como estacionalidad.
5. **Estacional (`seasonal`):** recalcula `SeasonalityAnalyzer` únicamente con los días del entrenamiento. Exige calidad global `recurrent`, doce índices positivos conocidos y ningún mes `possible`, `unstable` o `insufficient`. Desestacionaliza las FAC recientes, calcula su tasa base y aplica el factor del mes objetivo. La evidencia estacional descriptiva por sí sola no desplaza la media: el candidato también debe superar comparación temporal. Los factores cero se excluyen conservadoramente para evitar divisiones indeterminadas.
6. **Intermitente (`tsb_0.2`):** alternativa TSB sobre ocurrencia mensual y tamaño de tasa positiva. Se activa sólo con suficientes meses positivos y fracción explícita de meses nulos. Inicializa tamaño con la primera tasa positiva y probabilidad con el inverso de los meses transcurridos hasta ella. Después actualiza probabilidad cada mes y tamaño sólo en meses positivos, ambos con 0.2. La tasa esperada es probabilidad × tamaño; se convierte por duración del mes futuro. No se simulan meses futuros sin ventas para decaer artificialmente la probabilidad.

La adaptación TSB a tasas mensuales aproxima el efecto de la duración del mes; no constituye un modelo diario de ocurrencias ni una corrección de agotamientos. Con pocos eventos positivos se considera inelegible. En caso de empate o mejora insuficiente se conserva la media sencilla.

## Selección y reserva temporal

Se construyen orígenes mensuales expansivos. En cada origen se vuelve a obtener el tramo consecutivo conocido y se ajustan los candidatos desde cero. Tendencias, probabilidad intermitente y factores estacionales nunca se calculan con valores del objetivo o posteriores.

Se evalúan siempre los horizontes 1, 2, 3 y 6, además de los meses intermedios solicitados. Cada error corresponde a **un mes objetivo a distancia h**, no a la suma acumulada de los h meses. La selección puede diferir por horizonte; no se presenta la trayectoria resultante como un único modelo universal.

Los últimos `validation_origins` orígenes posibles se reservan para validación. Los objetivos de selección deben terminar antes o exactamente al comienzo del primer origen reservado. Esto introduce la separación necesaria a horizontes largos: no basta con que el origen de selección sea anterior si su objetivo ocurre después.

Se utilizan los últimos `minimum_selection` orígenes de selección prefijados, dentro de `maximum_origins`. No se sustituyen huecos por períodos antiguos más favorables. Todos los candidatos compiten sobre los mismos orígenes y objetivos; un candidato inelegible en alguno no desplaza la media. La elección usa MAE de selección y exige mejora relativa mínima respecto a la media. Al aumentar complejidad debe además mejorar el mejor MAE encontrado; un empate conserva el candidato anterior más sencillo.

La reserva final no selecciona parámetros ni cambia el método elegido. Sirve para determinar su categoría. Si el método elegido no confirma su ventaja en la reserva, permanece exploratorio; no se elige retrospectivamente otro ganador mirando esa reserva. El ajuste final sí puede incorporar todos los meses disponibles antes del corte, incluidos los meses ya observados de validación. Esa actualización es legítima: no utiliza datos posteriores al corte.

Los errores reservados de orígenes cercanos pueden depender entre sí. No representan muestras independientes ni una garantía de rendimiento futuro. Las revisiones retroactivas del ERP y la lectura actual de caché impiden reproducir exactamente qué datos eran conocidos en cada fecha antigua; es una validación temporal sobre la historia actualmente verificable, no una simulación transaccional perfecta.

## Métricas

Para cada par: `error = pronóstico − FAC_real`. Error positivo indica sobreestimación.

- Error absoluto agregado: suma de valores absolutos.
- Error firmado agregado: suma de errores.
- MAE: error absoluto agregado / observaciones.
- Sesgo en unidades: error firmado agregado / observaciones.
- WAPE: error absoluto agregado / suma de FAC reales absolutas.
- Sesgo relativo: error firmado agregado / suma de FAC reales absolutas.
- MASE: promedio de errores absolutos divididos por su escala de entrenamiento. La escala es la media de diferencias absolutas entre cantidades mensuales consecutivas de la ventana de ajuste (referencia ingenua no estacional).

Los WAPE y sesgos relativos se expresan como **razones**, no como porcentajes. Denominador cero produce NULL. MASE sólo se publica si todas las observaciones evaluadas tienen escala positiva; se conserva además `mase_observations`. Los efectos de distinta longitud de mes permanecen en esta referencia ingenua mensual de MASE y se documentan, sin presentarla como una referencia estacional. No se usa MAPE.

Cada horizonte conserva cantidades predichas/reales, origen, límites de entrenamiento, escala, errores y exclusiones. La salida CLI resume métricas; la auditoría detallada conserva todos los pares comparados. Un mes real desconocido o una unidad incompatible se excluye expresamente, nunca se transforma en cero.

## Parámetros iniciales y categorías

La configuración `replenishment.forecast` contiene parámetros estadísticos explícitos, ajustables según el uso; no son políticas comerciales de compra:

| Parámetro | Inicial | Interpretación |
|---|---:|---|
| `horizon` | 3 | Meses a emitir, de 1 a 6 |
| `window` | 12 | Meses recientes máximos de ajuste |
| `minimum_training` | 3 | Meses consecutivos mínimos para estimar |
| `minimum_selection` | 6 | Orígenes prefijados para comparar |
| `validation_origins` | 3 | Orígenes finales reservados |
| `maximum_origins` | 24 | Límite de búsqueda retrospectiva por horizonte |
| `alphas` | 0.2 / 0.5 / 0.8 | Candidatos SES fijos |
| `recent_weight` | 1 | Exponente de posición para media ponderada |
| `damping` | 0.8 | Amortiguación de tendencia |
| `maximum_trend_factor` | 2 | Límite de tasa extrapolada |
| `intermittent_fraction` | 0.4 | Fracción mínima de meses nulos para TSB |
| `minimum_positive_months` | 3 | Evidencia positiva mínima para TSB y categoría utilizable |
| `improvement` | 0.05 | Mejora mínima relativa de MAE frente a media |
| `maximum_wape` | 0.50 | Tolerancia reservada para categoría utilizable |
| `maximum_bias` | 0.25 | Tolerancia absoluta de sesgo relativo |
| `maximum_cache_age_days` | 30 | Antigüedad admitida de la caché para validar |

La política estacional, incluido `policy_defaults.seasonal_minimum_years`, sigue siendo la de Fase 5.1; no se alteran sus reglas.

- `usable`: entrenamiento suficiente, comparación y reserva con los conteos exigidos, WAPE y sesgo dentro de tolerancias, historia positiva suficiente, caché no antigua y ventaja confirmada frente a la media cuando se eligió otro método. Es una categoría operativa según estos parámetros, no una confianza estadística calibrada.
- `exploratory`: existe estimación justificable, pero no cumple alguna condición anterior. Sin backtesting se emite únicamente media exploratoria. Una serie sin ventas verificadas puede producir cero exploratorio; no permite porcentajes de error ni prueba demanda futura nula.
- `insufficient`: no existe el mínimo de meses consecutivos conocidos. Cantidad y método permanecen NULL.

Los límites predictivos y `confidence` permanecen NULL en todas las categorías. No se implementó calibración de intervalos. Se mantiene no negatividad. `forecast_quantity` conserva doce decimales internos; `display_quantity` redondea a dos sólo para presentación y no define múltiplos comerciales ni unidades de compra.

## Caché y persistencia

`--cache` nunca consulta silenciosamente ERP; una tabla propia ausente produce un error comprensible y no ejecuta migraciones. Los huecos o versiones/zonas incompatibles ya detectados por `ObservedDemandStore` se propagan como desconocidos. Una caché con fecha mínima antigua o frescura desconocida puede producir únicamente estimaciones exploratorias, conservando fechas y advertencia; no se promete sincronización con el ERP. La protección de Fase 5.1 contra reconstrucciones incompletas permanece intacta.

`ForecastStore` sólo publica lotes con cantidades conocidas y categorías `exploratory` o `usable`. La opción es explícita. Añade filas inmutables a `replenishment_demand_forecasts` y una `ProductMetric` cuya versión es `observed-forecast-1.0`. Su campo JSON existente contiene `forecast_audit` y `demand_forecast_ids`, sin rellenar columnas de ventas observadas con cantidades pronosticadas.

La auditoría relacionada conserva opciones, fuente, corte, meses, candidatos, errores, selección y categoría de **cada** pronóstico. La tabla existente no tiene columna de calidad: los consumidores posteriores deben leer esa auditoría y no tratar toda fila persistida como validada. La cantidad/base/factores se redondean a seis posiciones para las columnas existentes; la auditoría mantiene el cálculo interno. Nuevas ejecuciones añaden históricos, no sobrescriben anteriores. Un fallo revierte pronósticos y auditoría juntos mediante PersistenceConnection.

No se crean nuevas tablas, migraciones, conexiones ni dependencias. No hay escrituras ERP. No se calculan stock de seguridad, puntos de pedido, mínimos/máximos, cantidades o fechas de compra. No se inicia Fase 6.1.

## Resultado de comprobaciones focalizadas

Paquete: ForecastTest, 10 pruebas y 180 aserciones satisfactorias, PHP 8.2.28 / PHPUnit 11.5.57 / Laravel 12 instalado. Consumidor: filtro Forecast de OrbisHistoryTest, 2 pruebas y 29 aserciones satisfactorias, PHP 8.2.28 / PHPUnit 10.5.29 / Laravel 10 instalado. Total: 12 pruebas y 209 aserciones. Sintaxis nueva/modificada comprobada con PHP 8.1.33; PHPDoc verificado en contrato, clases y métodos nuevos del paquete y sus pruebas.

Se cubrieron tasa constante, crecimiento/descenso y recortes, intermitencia y ceros, historia corta/desconocida, meses parciales, bisiestos y horizontes, métricas con denominadores nulos, selección pareada, reserva sin filtración temporal, factores estacionales recalculados sólo en entrenamiento, índices inestables/picos, devoluciones sin cambio de FAC, caché antigua o de versión incompatible, consultas exclusivas, categorías exploratorias, límites NULL, precisión, no negatividad, históricos inmutables, rollback de publicación y separación de conexiones. No se repitió la matriz Laravel ni las suites completas de fases anteriores. No se amplió Composer ni se verificaron escrituras/migraciones reales en SQL Server o MySQL mediante estas pruebas.
