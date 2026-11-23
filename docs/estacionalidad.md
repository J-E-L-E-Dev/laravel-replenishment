# Estacionalidad mensual de ventas observadas — Fase 5.1

## Alcance

`SeasonalityAnalyzer` recibe `ObservedDemandData` y `SeasonalityOptions`, sin conexiones ERP. `SeasonalityQuery` reutiliza `ObservedDemandQuery` para consulta directa o `ObservedDemandStore` para caché exclusiva. `SeasonalityData` conserva la auditoría; `SeasonalityStore` añade históricos propios. No hay pronóstico, demanda perdida, estacionalidad semanal ni recomendaciones de compra.

La señal es `verified_gross_sales`: en el adaptador aprobado corresponde a FAC verificada. Las devoluciones NCC, sus reversiones NDC y el neto permanecen en los meses originales como indicadores complementarios. Una devolución posterior no se reasigna ni desplaza la concentración temporal de FAC. La fórmula comercial de Fase 4.1 no cambia. Una lectura comercial fallida invalida el conjunto, aunque una consulta SQL aislada pueda sumar algunas FAC.

## Calendario y selección

Se analizan meses calendario completos en zona operativa, con cortes semiabiertos. Se excluye el mes todavía en curso y cualquier mes recortado por los límites solicitados. Cada año aceptado requiere doce meses completos y conocidos, una unidad comparable, actividad positiva y el mínimo explícito de días con FAC. Se distinguen `partial_year`, `unknown_or_partial_months`, `zero_annual_activity`, `insufficient_active_days`, `unit_unverified` y exclusión por ventana histórica.

Un mes completo verificado sin FAC tiene cero; un mes desconocido tiene NULL. Un año parcial nunca se anualiza como doce meses. Un año completo sin actividad no tiene denominador positivo: no genera índices ni demuestra ausencia de estacionalidad. La ausencia de datos de años iniciales no demuestra historia comercial completa del producto.

La política considera los últimos `history_years` años civiles tocados por el intervalo. Si el último es parcial, consume una posición y se excluye; para utilizar cinco años completos conviene terminar el intervalo en el 1 de enero. Los límites de diez años de Fase 4.1 permanecen vigentes. La fuente comercial puede invalidar toda la serie por incompatibilidad de unidades; no se relaja esa protección para recuperar un patrón.

## Fórmula y aritmética

Para cada mes y año aceptado:

`índice = (FAC_mes / días_mes) / (FAC_año / días_año)`

Se evalúa como `FAC_mes × días_año / (FAC_año × días_mes)`, con una sola división decimal y doce decimales HALF_UP. Así se evitan diferencias por 28/29/30/31 días y redondeos intermedios. Índice uno significa la tasa anual habitual; mayor a uno indica tasa superior. Los ceros mensuales son válidos cuando el año tiene denominador positivo.

La consolidación usa la mediana de índices por mes. Se publican índices de cada año, magnitudes brutas/devoluciones/netas, tasas, amplitud máximo–mínimo y medianas recalculadas quitando un año cada vez. Esta sensibilidad permite ver el efecto de extremos sin borrarlos. Las tendencias muestran razones de tasas anuales y del mismo mes entre años consecutivos; una base cero deja la razón desconocida. Las tasas intermedias de tendencia tienen doce decimales, por lo que pueden presentar diferencias en las últimas posiciones.

La ponderación es explícitamente igual por año seleccionado. Después de normalizar la escala anual, no se presume que un año reciente sea más representativo. Para cambiar la representatividad se selecciona otra ventana y se conserva su auditoría; no hay pesos de recencia ocultos. Las tasas anuales extremas se señalan frente a la mediana, sin exclusión automática ni atribución a promociones. Una evolución estrictamente monótona dentro de todos los años válidos reduce los patrones recurrentes a posibles por confusión con tendencia; esto no sustituye un modelo de tendencia complejo.

## Parámetros y evidencia

Todos los valores siguientes son parámetros exploratorios configurables en `replenishment.seasonality`, no políticas comerciales de abastecimiento:

| Parámetro | Valor inicial | Uso |
|---|---:|---|
| `history_years` | 5 | Ventana civil reciente, máximo 10 |
| `minimum_years` | 3 | Mínimo de años válidos; nunca menos de 2 |
| `minimum_active_days` | 12 | Mínimo de días con ventas positivas por año |
| `high_index` | 1.20 | Umbral elevado |
| `low_index` | 0.80 | Umbral bajo |
| `recurrence` | 0.666666 | Fracción mínima de repetición |
| `maximum_spread` | 0.50 | Amplitud admisible para estabilidad |
| `outlier_factor` | 3 | Señal de escala anual extraordinaria |
| `alpha` | 0.05 | Nivel exploratorio del contraste de signos |

`replenishment.policy_defaults.seasonal_minimum_years`, si está definido, prevalece sobre `minimum_years`. El servicio no consulta una política persistida arbitraria: un consumidor que ya haya resuelto su política puede suministrar ese mínimo o construir `SeasonalityOptions` con el valor correspondiente. Los mínimos de actividad y años son decisiones metodológicas iniciales visibles, no prueba universal de suficiencia estadística; deben revisarse según el uso.

- `recurrent`: suficientes años, mediana fuera del umbral alto o bajo, repetición suficiente y amplitud admisible.
- `possible`: indicio limitado, incluido un pico aislado; nunca confirmación por un único año.
- `unstable`: amplitud excesiva; tiene prioridad en la calidad global cuando coexiste con meses recurrentes.
- `insufficient`: no se alcanza el mínimo de años válidos. El índice consolidado es NULL; los índices exploratorios de años realmente válidos se distinguen expresamente y no se persisten.
- `none_detected`: evidencia suficiente sin patrón alto o bajo conforme a los criterios. No equivale a ausencia de ventas ni garantiza ausencia de toda estacionalidad.

La etiqueta mensual se separa de `level` (`high`, `low`, `usual`). Un índice 1.05 no basta para declarar temporada alta. La recurrencia es descriptiva. Se publica un contraste unilateral exacto de signos sobre índices mayores a uno, con empates tratados conservadoramente y corrección Bonferroni por doce meses. No garantiza independencia de años, no prueba causas ni calibra confianza. `significant_after_12_tests` corresponde a meses individuales; las temporadas agrupadas no se presentan como estadísticamente confirmadas. `confidence` siempre es NULL.

## Temporadas y máximo

Se agrupan meses elevados consecutivos de forma circular. Noviembre–enero compara noviembre/diciembre del año de inicio con enero del siguiente; el último año sin enero verificado queda `years_unassessed`, no se cuenta como ausencia. Se publican años presentes/ausentes, extremos, índices e intensidad mediana. La temporada requiere además suficientes repeticiones completas para etiquetarse recurrente; una agrupación de meses inestables conserva esa limitación.

`peak_month` identifica el máximo índice consolidado, no el mayor volumen bruto de un mes largo. Si hay empate se devuelve NULL y todos los máximos en `peak_months`. Las magnitudes originales permanecen disponibles en `observed_months` y `years`.

## Persistencia y protección de caché

La consulta predeterminada no abre la conexión propia ni escribe. La opción explícita de persistencia inserta doce `SeasonalPattern` nuevos y una `ProductMetric` de auditoría en una única transacción de `PersistenceConnection`. La tabla estacional existente conserva mes, cantidad de años, índice, media mensual FAC observada, categoría, fecha y versión. Sus campos decimales se redondean a seis posiciones para almacenarlos; el cálculo completo de doce posiciones se conserva en la auditoría.

No se necesitan migraciones: `ProductMetric.observed_sales_summary` contiene `seasonality` y `seasonal_pattern_ids`, con años concretos, exclusiones, unidades, parámetros, fechas de caché y resultados. La métrica usa la versión `observed-seasonality-1.0` y no inventa otras métricas de demanda. Al consultar métricas históricas debe distinguirse la versión/clave de auditoría de las métricas comerciales de Fase 4.1. Todo el lote es inmutable; repetir un cálculo añade otro lote. Un fallo revierte patrones y auditoría juntos. Los resultados globalmente insuficientes no se publican.

La corrección focalizada de `ObservedDemandStore` rechaza cualquier reconstrucción incompleta antes de escribir, dentro de la transacción. Valida el resumen y cada día esperado; no reemplaza datos completos por NULL ni mezcla días antiguos con una métrica parcial nueva. Se conserva la posibilidad de reconstruir períodos completos verificados, incluso ceros. Una fuente incompleta tampoco crea caché nueva. Esto cambia deliberadamente la conducta anterior de persistir desconocidos y queda cubierto por regresión.

## Límites históricos

Los índices describen ventas observadas, no toda la demanda potencial. Fase 4.2 no acreditó días agotados en sus seis pilotos. La disponibilidad comercial antigua y la demanda perdida siguen desconocidas. Un agotamiento no identificado podría deprimir ventas estacionales. No se consulta I00097 ni se corrige la serie mediante saldos puntuales.

Las pruebas usan series ficticias y SQLite aislado. Las lecturas de SQL Server del consumidor no certifican escrituras o migraciones reales en SQL Server/MySQL. No se amplía compatibilidad Composer ni se inicia Fase 5.2.

Resultado final de verificación: paquete 13 pruebas / 94 aserciones (SeasonalityTest y tres regresiones focalizadas de caché/atomicidad); consumidor 2 pruebas / 26 aserciones (filtro Seasonality de OrbisHistoryTest). Total: 15 pruebas y 120 aserciones satisfactorias. Ejecución PHP 8.2.28 con PHPUnit 11.5.57/Laravel 12 en el paquete y PHPUnit 10.5.29/Laravel 10 en el consumidor. Sintaxis nueva/modificada comprobada con PHP 8.1.33 y PHPDoc verificado en las clases y métodos nuevos del núcleo. No se repitió la matriz completa de compatibilidad.
