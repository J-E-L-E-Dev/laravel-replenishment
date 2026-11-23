# Ajuste 7.3.3 — Fuentes comerciales y corte estadístico

## Estado de implementación

Las etapas A, B y C están implementadas. El adaptador opcional `commercial_history` expone el libro comercial por línea, con unidad histórica, procedencia documental y ventana efectiva. Se resuelve por `AdapterRegistry` y sólo lee. Desde C las nuevas estadísticas utilizan el contrato configurado. Las instalaciones que sólo declaran `SaleSource` mantienen el flujo anterior.

## Exclusiones

`replenishment.sales.document_exclusions` es una lista vacía por defecto. `DocumentExclusions` valida operadores `like`, `=` e `in`. Los valores son textos no vacíos; IN requiere una lista textual no vacía. No se convierten documentos numéricos ni se pierden ceros iniciales. Las reglas se aplican como `NOT (regla_1 OR regla_2 ...)`, con parámetros vinculados mediante Query Builder. Las identidades NULL se conservan en el resultado para diagnosticar identificadores ausentes, en lugar de omitir evidencia silenciosamente.

Perfil de lectura comercial de consult-web

```php
'sales' => [
    'document_exclusions' => [
        ['operator' => 'like', 'value' => '31%'],
    ],
],
```

La huella `fingerprint()` identifica exactamente la lista de reglas para su futura incorporación a la identidad de caché. Las reglas se aplican en SQL antes de paginar los movimientos de `commercial_history`. El Kardex, las recepciones y la consulta por identidad documental conservan su alcance completo.

## Corte

Configuración genérica:

```php
'statistics_cutoff' => [
    'enabled' => false,
    'connection' => null,
    'table' => null,
    'column' => 'corte_de_estadisticas',
],
```

`StatisticsCutoff::forProduct()` recibe además la columna de identidad del adaptador. Deshabilitado no abre conexiones ni consulta la columna. Habilitado exige una conexión existente y destino válido, y diagnostica la instalación pendiente. NULL significa que no hay corte adicional; una fecha se valida estrictamente como fecha civil.

`effectiveStart()` devuelve el máximo entre inicio solicitado y el día siguiente al corte inclusivo, en la zona operativa. El llamador debe comprobar que el inicio sea anterior al fin exclusivo y aplicar los mínimos de historia. El ejemplo 02/08/2026–18/11/2026 tiene 108 días. La proyección comercial usa este límite antes de consultar movimientos.

## Publicación y ejecución manual

Publicar sólo por decisión del operador:

```text
php artisan vendor:publish --tag=replenishment-statistics-cutoff-migration
```

El archivo se copia a `database/migrations/replenishment-erp/2026_11_18_000001_add_replenishment_statistics_cutoff.php`. No se carga por el migrador dedicado `replenishment:migrate` ni se publica automáticamente durante la instalación. No se han ejecutado estos comandos sobre consult-web.

Antes de ejecutarlo, revisar el archivo publicado, configurar explícitamente `enabled`, `connection`, `table` y `column`, y verificar permisos del destino. Para el destino SQL Server propuesto por el usuario, los valores serán `sqlsrv`, `dbo.I00001` y `corte_de_estadisticas`.

Ejemplo de ejecución exclusiva, posterior a esa revisión:

```text
php artisan migrate --database=sqlsrv --path=database/migrations/replenishment-erp/2026_11_18_000001_add_replenishment_statistics_cutoff.php
```

La conexión `--database` determina el repositorio estándar `migrations` de Laravel. La columna se modifica en la conexión nombrada en `statistics_cutoff.connection`, mediante `Schema::connection()` explícito. Son decisiones distintas: indicar otra conexión en `--database` registraría el historial allí sin redirigir el destino de la columna. El ejemplo usa el mismo nombre intencionalmente. No se utiliza `replenishment_migrations` para esta extensión externa.

Se inspeccionan metadatos para SQLite, MySQL y SQL Server. Una columna existente sólo se acepta si es DATE y admite NULL. No se altera su tipo, nulabilidad ni datos. Otros drivers fallan explícitamente. La validación ejecutada hasta ahora es SQLite temporal; no acredita ejecución real en MySQL o SQL Server ni permisos DDL del destino comercial.

## Reversión protegida

`down()` siempre lanza un diagnóstico antes de leer configuración o modificar esquemas. No elimina la columna, aunque esté vacía, porque podría ser preexistente o tener un destino configurado posteriormente distinto. El rollback automático se interrumpe y conserva el historial de Laravel. Cualquier reversión requiere revisión manual del destino original y de los datos; esta implementación no intenta deducir propiedad de la columna.

## Comprobaciones de la etapa A

`tests/StatisticsConfiguration733Test.php`: 8 pruebas, 25 aserciones, SQLite en memoria con conexiones separadas. Cubre combinación de exclusiones, parámetros, errores, modo deshabilitado, columna compatible/incompatible, ausencia de instalación, protección de rollback, ventana de 108 días y publicación separada. Sintaxis verificada con PHP 8.1.33; pruebas ejecutadas con PHP 8.2.28.

No se ejecutaron pruebas frontend ni Vite en esta etapa sin cambios de interfaz. No se consultó el ERP real, no se insertaron fixtures comerciales y no se ejecutó ALTER TABLE real.


## Lectura por producto

```php
$source = app(\Asisnet\Replenishment\Services\AdapterRegistry::class)->resolve('commercial_history');
$zone = new \DateTimeZone(config('app.timezone'));
$window = $source->window('018-013', config('replenishment-orbis.location_id'),
    new \DateTimeImmutable('2026-01-01', $zone), new \DateTimeImmutable('2026-11-18', $zone));
foreach ($window->movements as $movement) {
    // Leer id, transactionType, historicalSaleQuantity, historicalSaleUnit, demandQuantity e issues.
}
```

`requestedStart`, `effectiveStart`, `until` y `cutoffDate` se entregan también con cero movimientos. El fin es exclusivo. Si el inicio efectivo alcanza o supera el fin, se omite la lectura de I00017. Los días anteriores al inicio efectivo quedan fuera de observación. La consulta mantiene el orden fecha/idregistro y un límite superior de identidad por lectura. Las ediciones concurrentes del ERP pueden cambiar el resultado entre consultas.

En consult-web el corte está deshabilitado por defecto. `REPLENISHMENT_STATISTICS_CUTOFF_ENABLED` controla la lectura de `dbo.I00001.corte_de_estadisticas` por `producto` en la conexión Laravel `REPLENISHMENT_ERP_CONNECTION` (predeterminada `sqlsrv`). `ProductData::statisticsCutoffDate` es opcional. Una fecha NULL conserva el inicio solicitado. Habilitar la lectura cuando falta la columna produce un diagnóstico de instalación pendiente. La comprobación SQL Server usa el nombre de tabla con esquema.

### Unidades, signos y evidencia

| Transacción | Cantidad registrada | Efecto físico cuando es verificable |
|---|---|---|
| FAC, NDC | Positiva | Negativo |
| NCC | Negativa | Positivo |
| ENT, NCP | Positiva | Positivo |
| NDP | Negativa | Negativo |
| SAL | Positiva | Negativo |
| AJU | Firmada | Mismo signo |

La línea conserva su `idregistro`, cantidad original y transacción cruda. `transactionType` normaliza espacios y mayúsculas. En ventas, `historicalSaleQuantity` representa la cantidad firmada verificable en `historicalSaleUnit` (I00017.unidaventa). `demandQuantity` expresa su equivalente comercial en la unidad actual si coincide con I00001.unidamedid. Así, 120 UND antiguas y 10 CJA actuales producen dos observaciones; sólo las 10 CJA tienen equivalencia directa actual. I00017.unidad sigue siendo evidencia física. `baseQuantity`, `baseUnit` e `inventoryDelta` conservan las comprobaciones físicas. Ninguna presentación del proveedor aporta una equivalencia histórica.

Los detalles se buscan en I00017 por producto, ventana y transacción. Los encabezados I00018 se agrupan por el par textual documento/número, por bloques. La referencia de operación (`operation_reference`) puede repetirse; `invoice_reference` identifica la factura original aunque sea anterior al intervalo. Los valores tipo y relaciona se conservan como evidencia. Orbis deja de emitir `unknown_document_status` por el contenido de tipo. El analizador físico conserva la interpretación de incidencias expresamente comunicadas por otros adaptadores. Cada devolución o reversión cuenta en su fecha de detalle. Los códigos de I00055 se cotejan como texto, incluidos los ceros iniciales. Sus indicadores esanular, fiscal, enterbaja y estadistic carecen de efecto excluyente.

Un detalle comercial firmado con unidad verificada puede conservar su cantidad si falta el encabezado o la definición, o si existe un indicador de conversión. Estas limitaciones quedan en `issues` y pueden impedir acreditar el efecto físico. Identidad documental inválida, encabezados múltiples, transacción contradictoria, signo incorrecto y unidad de venta ausente mantienen desconocida la cantidad comercial verificable. La cantidad original permanece disponible para inspección. Una identidad de línea o fecha ilegible impide afirmar que la lectura está completa.

### Selección y compras

`OrbisStatisticalProductSelection::page($after, $limit)` pagina códigos por orden textual con cursor exclusivo. `reasons($productId)` explica las exclusiones. El perfil local `replenishment-orbis.statistics_selection` requiere verexisten distinto de N, descripción fuera de zz% e yy% y fechacompr estrictamente posterior al 01/01/2000. Cada NULL impide cumplir esa condición y genera un motivo diferenciado. El perfil sólo rige este selector. La consulta directa de un producto, su historia y sus relaciones sigue disponible.

`receiptsBetween` lee ENT, NDP y NCP con sus signos aunque falte la orden. Para conciliar una orden se contrasta I00018.orden con C02092.documento y C02092.numero concatenados y se comprueban sus líneas C02091. La recepción observada, el vínculo documental, el plazo certificado y el pendiente comercial son evidencias distintas. `pendingFor` conserva su diagnóstico de imposibilidad de certificar el pendiente.

## Conciliación de 018-013

SELECT de sólo lectura en SQL Server de consult-web, del 1 de enero al 18 de noviembre de 2026 (fin exclusivo). Se cotejaron identidades, signos, cantidades y unidades originales con el flujo del adaptador.

| Mes | FAC (UND) | NCC firmada (UND) | NDC (UND) | Neto (UND) |
|---|---:|---:|---:|---:|
| Enero | 1 | 0 | 0 | 1 |
| Marzo | 1 | 0 | 0 | 1 |
| Abril | 1 | 0 | 0 | 1 |
| Mayo | 2 | −1 | 0 | 1 |
| Julio | 1 | 0 | 0 | 1 |
| Agosto | 1 | 0 | 0 | 1 |
| Octubre | 1 | 0 | 0 | 1 |
| **Total** | **8** | **−1** | **0** | **7** |

Nueve detalles comerciales, nueve identidades únicas, cero excluidos por 31%, cero diferencias con los detalles SQL. Todos tenían unidad UND y precisión de día. Se observaron dos ENT de 6 UND, ambas sin referencia de orden. El Kardex devolvió once movimientos, variación física +5 UND y último saldo registrado de 6 UND. El saldo de apertura deducido es 1 UND. La igualdad del período es 1 + 12 − 8 + 1 = 6.

Al tomar sólo fechas posteriores al corte hipotético 01/08/2026, la muestra real conserva FAC 2, NCC 0, NDC 0, neto 2 y ENT 6 UND. Se filtraron en memoria los movimientos leídos. El comportamiento del corte en la consulta y en una ventana vacía se probó por separado en SQLite. La inspección real encontró pendiente la columna optativa. El saldo del ERP permaneció intacto.

## Transición a la etapa C

El adaptador `sales` mantiene el alcance documental histórico para las series ya materializadas. Comprueba la elegibilidad documental en la misma fila que lee. Si encuentra un documento comercial excluido, un intervalo recortado o un evento incompatible con la unidad actual, interrumpe la lectura. El calculador existente declara la serie incompleta y su almacén rechaza publicarla. La nueva proyección `commercial_history` conserva las observaciones individuales para conciliación, sin entregar sus unidades originales como valores base al agregador.

La etapa C, documentada a continuación, incorpora la agregación por unidad, los días fuera de observación y la identidad de perfil y ejecución. La etapa B no reconstruye cachés ni modifica propuestas.

### Verificación de B

En consult-web se ejecutaron OrbisHistoryTest, OrbisPurchaseHistoryTest y OrbisAvailabilityTest en SQLite temporal con PHP 8.2.28. Pasaron 65 pruebas con 473 aserciones. En el paquete pasaron 54 de 56 pruebas de configuración, DTO, historia, demanda observada, conciliación y registro de adaptadores. Dos comprobaciones históricas conservan expectativas anteriores a B. FoundationTest espera un solo recurso publicable y el proveedor ya expone tres. ObservedDemandTest espera cuatro migraciones dedicadas y el paquete ya distribuye seis. No se modificaron esas expectativas ni el esquema. La sintaxis de los 19 archivos PHP de esta etapa se verificó con PHP 8.1.33. La lectura SQL Server documentada arriba corresponde exclusivamente al producto y al intervalo indicados.

## Etapa C — Estadísticas por unidad histórica

### Lectura y cobertura

Con `replenishment.adapters.commercial_history` configurado, `CommercialSalesQuery::read` consulta el maestro y la ventana comercial del producto. Valida el intervalo civil semiabierto, el destino, la identidad y la fecha de cada detalle. Comprueba la unicidad de las identidades con el índice temporal por bloques antes de acreditar los ceros. Un fallo tardío o un duplicado invalida la ventana efectiva completa. El servicio entrega días desconocidos y un diagnóstico. Una cantidad o unidad indeterminada en una línea identificada deja incompleto su día y conserva las magnitudes verificadas de otras líneas.

| Estado diario | Interpretación |
|---|---|
| `complete` | Todos los detalles del día pudieron clasificarse en sus unidades históricas. |
| `zero_observed` | La fuente terminó la lectura del día y no entregó movimientos admitidos. Se conserva una observación con cero eventos y sin filas de unidad. |
| `incomplete` | La lectura terminó, pero al menos una línea impide certificar el total del día. |
| `unknown` | La lectura o la identidad global de la serie falló, o falta una ejecución compatible. |
| `outside_cutoff` | El día precede al inicio efectivo. El conteo queda en NULL. |

El inicio efectivo es el mayor entre el solicitado y el día posterior al corte. Una ventana íntegramente excluida contiene sólo días marcados fuera del corte. El cero observado describe actividad comercial registrada. Carece de información sobre demanda potencial, existencias o presentación vigente.

### Magnitudes y calendario

Cada grupo diario corresponde a una unidad de venta histórica. Conserva FAC positiva, NCC como magnitud devuelta positiva, NDC positiva y el neto firmado FAC − NCC + NDC. Un día con más de una unidad contiene varios grupos. Las semanas empiezan el lunes y terminan el domingo, recortadas al mes. El primer o último período puede estar recortado además por el intervalo solicitado. Los meses y años son civiles.

`CommercialSalesReport::breakdown` devuelve días, semanas, meses, años y resumen. Cada período identifica cobertura efectiva, días observados, ceros, días excluidos, incompletos, desconocidos e incompatibles con la unidad requerida. `observed_by_unit` conserva cantidades parciales identificadas. `totals_by_unit` sólo contiene totales definitivos cuando hay días efectivos y su cobertura es completa. Un intervalo observado sin movimientos devuelve una lista vacía. Uno sin cobertura certificada devuelve NULL.

### Perfil, ejecución y caché

La versión es `observed-sales-by-unit-2.0`. La identidad incluye el ámbito público del origen, clases de los adaptadores comercial y de producto, producto, ubicación, zona horaria, versión declarada del normalizador y del agregador, unidad actual, corte de la ficha, configuración aplicada al corte y exclusiones documentales. La huella SHA-256 utiliza JSON canónico. Las reglas de exclusión equivalentes se ordenan y deduplican antes de calcular la huella de `DocumentExclusions`. La selección de candidatos del catálogo no altera una consulta por producto y queda fuera del perfil.

En consult-web, `REPLENISHMENT_STATISTICS_SCOPE` identifica públicamente el origen. Su valor predeterminado incluye consult-web y el nombre de la conexión ERP. Al cambiar de catálogo, empresa o semántica de extracción, asignar un ámbito distinto o actualizar `replenishment.sales.normalizer_version`. Utilizar identificadores públicos sin cadenas de conexión ni credenciales. Los límites solicitados y la fecha de cálculo identifican la lectura, separados de la huella semántica.

| Tabla propia | Identidad y contenido |
|---|---|
| `replenishment_sales_runs` | ID de ejecución, producto, ubicación, perfil JSON, huellas completa y de ámbito, estado, inicio solicitado y efectivo, fin exclusivo e instante de cálculo UTC. |
| `replenishment_sales_days` | Una fila por ejecución y fecha, con estado de cobertura, conteo e incidencias. |
| `replenishment_sales_unit_totals` | Una fila por observación diaria y unidad con movimiento, con unidad textual, huella de unidad, FAC, NCC, NDC, neto y conteo. |

Las cantidades usan DECIMAL(24,6) y texto exacto en SQLite. Las claves foráneas sólo relacionan tablas propias. La lectura del ERP termina antes de abrir la transacción de publicación. En memoria se mantienen días y grupos por unidad para el intervalo acotado de hasta diez años. El índice de identidades utiliza bloques temporales. Una única transacción en `PersistenceConnection` añade la cabecera, las observaciones y sus magnitudes. Una interrupción deja un diagnóstico al llamador y conserva la última publicación. Los fallos no crean cabeceras parciales.

Dos publicaciones concurrentes pueden conservar sendas ejecuciones completas. La selección usa instante de inicio del cálculo e ID como desempate, y fija una sola cabecera que cubra todo el intervalo. Cada lectura posterior de días y unidades pertenece a ese ID. La consulta histórica por ID conserva ejecuciones anteriores. La lectura por bloques del ERP mantiene la limitación de instantánea distribuida indicada en B.

### Consultas y proyección para motores

| Entrada | Resultado y efectos |
|---|---|
| `CommercialSalesQuery::read(product, location, from, until, timezone)` | Consulta directa al origen, sin persistencia. |
| `CommercialSalesStore::materialize(...)` | Mismos argumentos. Lee y publica una ejecución completa en la conexión propia. |
| `CommercialSalesStore::execution(id)` | Recupera la publicación histórica indicada, sin comprobar la ficha actual. |
| `CommercialSalesStore::cached(..., master: ficha)` | Exige coincidencia del perfil de la ficha independiente y cobertura del intervalo en una ejecución. Admite fijar su ID. |
| `CommercialSalesStore::cached(..., snapshot: true)` | Lee una fotografía cuyo ámbito de configuración coincide. Expone `master_not_rechecked`. |
| `CommercialSalesReport::project(serie)` | Entrega `ObservedDemandData` en la unidad actual del perfil y conserva la versión e identidad de origen. |

Una petición de caché vigente sin ficha, o sin ejecución compatible, devuelve `refresh_required` con el motivo. El dashboard lee una sola fotografía para su intervalo y prescinde del relleno desde métricas mensuales antiguas al usar C. La lectura de estadísticas no consulta al ERP para verificar vigencia. La identificación de ficha por código de la pantalla conserva su propia consulta preexistente. La planificación puede aportar la ficha que ya ha verificado y fijar la ejecución de historia para tendencia y entrenamiento. Las versiones previas de caché, métricas y evidencias de propuestas permanecen consultables por sus mecanismos históricos.

La proyección homogénea admite un día completo con movimientos únicamente en la unidad requerida. Un día mixto o con otra unidad queda inutilizable para ese motor. Un día sin movimientos tampoco acredita qué unidad regía entonces. Se eligió conservar ese día como cero en el informe histórico y como cantidad desconocida para el entrenamiento. Los tramos comparables se componen así de días consecutivos respaldados directamente. No se interpola una fecha de cambio de presentación. Esto puede producir un pronóstico insuficiente para un producto con largo historial pero ventas esporádicas.

Sin adaptador comercial opcional, `ObservedDemandQuery` y el almacén anterior continúan usando `SaleSource` y `observed-sales-1.0`. Un fallo del adaptador configurado no habilita esa ruta alternativa. Cada auditoría informa de la versión real de sus datos. La persistencia heredada rechaza una proyección v2.

### CLI y despliegue posterior

Desde consult-web, para una consulta sin escrituras:

```text
php artisan replenishment:demand 018-013 --from=2026-01-01 --until=2026-11-18
```

`--location` selecciona el ámbito lógico. El fin es exclusivo y omitir las fechas consulta los últimos 365 días completos. El resultado JSON de C contiene el perfil y su huella, origen, ejecución cuando existe, límites, estado, días y desgloses por unidad. `--persist` solicita publicación en la conexión propia después de leer el ERP. `--cache` consulta exclusivamente la fotografía materializada. Las dos opciones son excluyentes. Los comandos existentes de estacionalidad y pronóstico permiten usar explícitamente la fotografía con `--cache` y conservan la evidencia de su origen.

La instalación dedicada aplica la nueva migración con `php artisan replenishment:migrate` cuando el operador decida desplegarla. En instalaciones que ya usan el historial estándar se publica el nuevo envoltorio con `php artisan vendor:publish --tag=replenishment-migrations` y se ejecuta `php artisan migrate --database=NOMBRE_DE_CONEXION_PROPIA --path=database/migrations/replenishment`, sustituyendo el nombre por el de la conexión Laravel propia. Conservar la modalidad instalada. La migración opcional ERP pertenece a otra etiqueta. El corte de consult-web continúa deshabilitado. La primera publicación v2 se solicita expresamente por producto. No hay adopción de filas antiguas ni actualización de propuestas.

### Conciliaciones de C

Para 018-013, la consulta directa a SQL Server del 01/01/2026 al 18/11/2026 exclusivo confirmó las cantidades mensuales de B: 321 días efectivos, 314 sin movimientos, nueve detalles en siete días, FAC 8, NCC 1, NDC 0 y neto 7 UND. La lectura de los días posteriores al corte hipotético del 01/08/2026 dio neto 2 UND. El fixture aislado verificó también la aplicación del corte en origen y la exclusión de las recepciones del agregado.

En la prueba de cambio de unidad se obtuvieron 280 FAC y 10 NCC en UND (neto 270), y 20 FAC y 2 NCC en CJA (neto 18). Marzo conservó 60 UND y 5 CJA el mismo día. Las sumas semanales, mensuales y anuales reconciliaron por unidad. Los motores siguen sujetos a sus requisitos anteriores de meses y evidencia. Las estrategias y actualización masiva corresponden a D, y la presentación analítica por unidad corresponde a E.


### Evidencia de pruebas de C

Se comprobaron 114 pruebas distintas del paquete y 31 del consumidor en PHP 8.2.28. Las ejecuciones focalizadas finales pasaron. La protección de publicación se ejercitó en un archivo SQLite temporal con dos conexiones independientes, una excepción después de insertar el primer día y dos materializaciones intercaladas. La lectura concurrente sólo vio la cabecera ya confirmada. El entrenamiento en CJA conservó el mismo pronóstico al cambiar de 120 a 9.000.000 la cantidad histórica en UND.

Las dos pruebas pendientes de B ahora comprueban las tres etiquetas y destinos de publicación y las siete identidades de migración en orden. La reversión temporal usa el migrador dedicado, por lotes y en orden inverso. La extensión ERP queda fuera de su historial. Se validó la sintaxis de 35 archivos con PHP 8.1.33. La verificación de SQL Server fue exclusivamente de lectura; la publicación y concurrencia en motores comerciales reales permanecen pendientes.

## D1 · Demanda efectiva por política

El algoritmo `policy-planning-2.0` lee una publicación comercial materializada del producto, ubicación, zona, unidad actual y perfil vigentes. La demanda y la tendencia comparten esa ejecución. Al faltar cobertura devuelve `refresh_required`. La generación conserva la política que el comprador congeló, el actor del consentimiento, las fechas y la identidad de la publicación. El procesamiento y la revalidación leen la historia antes de adquirir el bloqueo de escritura. La versión de generación y la identidad de la línea se verifican bajo bloqueo.

### Ventana y continuidad

La fecha de evaluación es el fin exclusivo. El inicio efectivo es el posterior entre el inicio solicitado y el día siguiente al corte estadístico. La decisión registra días excluidos, desconocidos, incompatibles, originalmente sin movimientos e incorporados por hipótesis. Un mínimo de 180 días sigue exigiendo 180 aunque el corte deje sólo 108.

El modo estricto conserva la proyección de C. Un día observado sin movimientos acredita una lectura comercial completa de ese día. Su unidad histórica puede seguir sin demostrarse. La continuidad asumida requiere aceptación expresa del comprador al guardar los parámetros y queda atribuida al usuario autenticado. Sólo en la proyección exploratoria se considera cero en esos días. Los datos originales, el entrenamiento y la validación retrospectiva conservan sus reglas.

Para el promedio general se exige compatibilidad en la ventana efectiva completa. Al solicitar bloques recientes o ponderados se admite el tramo continuo posterior al último día con una unidad incompatible. La evidencia identifica el comienzo exacto del tramo utilizado, los días excluidos antes de él y el mínimo comprobado. Esa frontera delimita la estimación y no demuestra una fecha de cambio de presentación. Un hueco de lectura impide usar ese recorte. Si toda la ventana carece de ventas, la demanda futura queda desconocida. Una tasa manual cero requiere revisión comercial.

### Señales y estrategias

Las ventas brutas acumulan FAC. Las netas acumulan FAC menos NCC más NDC. Se conservan separadas las tres magnitudes y los netos históricos negativos. Una tasa histórica negativa impide estimar el consumo futuro con ese bloque.

| Estrategia | Cálculo sobre días comparables |
| --- | --- |
| General | Cantidad de la señal dividida entre los días del intervalo efectivo completo. |
| Reciente | Cantidad de los últimos 30, 60, 90 o 180 días dividida entre la longitud elegida. El mínimo de historia disponible se comprueba por separado. |
| Ponderada | Suma de las tasas diarias de bloques contiguos multiplicadas por sus respectivos porcentajes. Los bloques se especifican desde el más reciente hacia atrás. |

El servidor exige pesos positivos que sumen exactamente 100 % y cobertura íntegra para todos los bloques. Conserva fechas, cantidades, tasas racionales y pesos. Brick Math multiplica la tasa exacta por los días de cada tramo. Para el balance decimal, la protección total se redondea una sola vez a doce decimales y el segundo tramo conserva la diferencia con el primero.

La primera opción, cuando se habilita, es el pronóstico utilizable de la misma señal, perfil y ejecución que cubre ambos tramos en meses completos. Los pronósticos existentes usan ventas brutas. Le siguen la estrategia histórica con autorización exploratoria y la tasa manual individual explícita. Una tasa manual sólo puede congelarse para un producto. La tendencia compara bloques no superpuestos dentro de cada par, identifica señal, frecuencia de días con señal positiva y cambios relativos. La intermitencia y las posibles restricciones de inventario limitan la interpretación de una caída.

### Protección y evidencia

Plazo y cobertura emplean la misma fuente. La protección automática suma ambas duraciones. El escenario conservador puede usar la mayor tasa general o reciente del segmento compatible, una tasa manual individual o quedar desactivado. Un escenario inferior al central queda rechazado y exige revisión. Cuando el central cubre y el conservador presenta déficit, el comprador ve «Revisión necesaria». La ausencia de seguridad o de entradas verificadas mantiene sus reglas anteriores, incluida la hipótesis separada de cero entradas. Las cantidades exploratorias y sus costos de presentación no constituyen órdenes.

La migración propia `2026_11_19_000008_add_policy_demand_settings` añade dos columnas opcionales a políticas. Los registros previos mantienen ventas brutas, promedio general, continuidad estricta y preferencia por pronóstico. La aplicación de la migración corresponde al procedimiento de despliegue de la instalación. Las propuestas y sus evidencias ya publicadas conservan sus versiones.

## D2 · Preparar estadísticas y procesar una selección

La selección congela proveedor, ubicación, política, hipótesis, zona nominal, fecha operativa y modalidad. El intervalo termina al inicio del día operativo y comienza `analysis_days` días antes. Si existe corte, el intervalo efectivo empieza después del último día excluido. Se conserva el mínimo de historia de D1.

| Modalidad | Lectura de movimientos comerciales |
| --- | --- |
| `reuse_or_refresh_missing` (predeterminada) | Recupera una publicación completa compatible. Si falta, lee el intervalo y lo publica. |
| `force_refresh` | Lee nuevamente la fuente, incluso con publicación previa. Permite incorporar correcciones retroactivas. |
| `cache_only` | Consulta la caché. Si falta la cobertura compatible, registra `refresh_required`. |

Estas modalidades afectan al histórico de ventas. Inventario, reservas, plazo, presentación, costo y entradas siguen sus reglas de vigencia. La reutilización carece de un límite implícito de antigüedad. El detalle conserva el instante original de materialización.

El perfil compara producto, ubicación, zona, unidad actual, corte, exclusiones, normalizador y versión de agregación. Una sola ejecución completa debe cubrir el intervalo efectivo. Preparación, demanda, tendencia y selección de pronóstico comparten el mismo `sales_run_id`; la auditoría conserva huella, ventana, procedencia, hora y versiones. La publicación y el vínculo al intento se confirman juntos por `PersistenceConnection`. Si falla la fuente comercial configurada, la generación registra el fallo. Para instalaciones que sólo implementan `SaleSource` se mantiene el recorrido de caché diaria y métricas anterior. Se compara una huella del contenido diario preparado al calcular y revalidar. Esa caché heredada carece de ejecuciones comerciales versionadas por producto.

### Recuperación y concurrencia

La generación conserva un manifiesto de hasta 10 000 relaciones. El servidor fija hasta 100 líneas por transacción y confirma cada cálculo por separado. Antes de leer movimientos, reserva la línea y registra intento, actor, UUID y versión. La preparación consulta el ERP fuera de la transacción de la generación. El cambio a cálculo renueva la reserva. Cada confirmación vuelve a comprobar propietario y versión. Una interrupción deja visible el intento. A los diez minutos puede recuperarse la operación correspondiente. Si la publicación ya quedó vinculada y sigue siendo compatible se recupera por su identidad, incluso en actualización forzada. Un trabajador desplazado pierde la capacidad de confirmar. Las consultas simultáneas a nivel de sesiones y motores comerciales requieren validación en una instancia aislada.

El progreso cuenta unidades pendientes, en curso y terminadas. Los diagnósticos comerciales pueden solaparse y no intervienen en el porcentaje. Los fallos quedan por producto con etapa y diagnóstico público. El reintento es explícito. Tras tres fallos recuperables consecutivos se exige intervención y una nueva actualización expresa. La aplicación conserva el identificador al recargar y sólo emite nuevos comandos cuando el comprador reanuda. Las lecturas paginadas, el presupuesto y el detalle no avanzan el proceso. Pausar admite la confirmación del producto en vuelo.

Al cambiar el día en la zona operativa, la ejecución conserva su evidencia. Se puede terminar de fijar la selección y derivar otra evaluación con la política congelada y fecha vigente para todas las líneas, las que necesitan actualización o los errores recuperables. Si la versión del origen cambia durante la copia de una selección grande, se exige derivarla otra vez desde la versión vigente.

### Revisión y propuesta

Actualizar un producto conserva su primer cálculo y registra las revisiones. Las cantidades manuales y exclusiones previas quedan pendientes de revisión. El detalle compara sugerencias, cantidad decidida, costo, subtotal y cobertura. El guardado exige una decisión por línea y revalida la publicación original, el perfil y las condiciones comerciales vigentes. Una discrepancia identifica el producto y permite revisarlo en la misma construcción. Las líneas ya incorporadas y propuestas aprobadas conservan sus evidencias. El guardado reanudable usa una sola cabecera. Cada evento de construcción conserva su versión, cabecera, generación y línea afectada antes y después del comando. El campo `items_scope` indica este alcance. Cada incorporación añade evidencia proporcional a la línea modificada. Los eventos anteriores y las operaciones ordinarias de propuestas conservan su formato. La construcción parcial sólo se libera al completar o cancelar formalmente.

La vista previa de decisión masiva identifica generación, versión, filtro, total elegible y hasta 25 líneas. La confirmación requiere motivo y permiso de gestión. El servidor verifica de nuevo la versión y el total, aplica las reglas de cada decisión en una transacción y registra actor, fecha y motivo. Son elegibles las sugerencias positivas calculables para aceptar, o las necesidades cero aptas para excluir. Las discrepancias, cantidades desconocidas, decisiones existentes y revisiones pendientes se atienden individualmente.

### Despliegue y comprobación

La evolución propia `2026_11_19_000009_add_generation_work` añade manifiesto, cursor, estado técnico, índices, reserva, intentos y revisiones. La modalidad y las evidencias reutilizan las instantáneas existentes. Las evoluciones 000007 y 000008 son prerrequisitos. En una ventana autorizada, respaldar la persistencia propia, comprobar el destino de `REPLENISHMENT_DB_CONNECTION` y aplicar el mecanismo registrado por la instalación. En modalidad dedicada el operador utiliza `php artisan replenishment:migrate`. En modalidad publicada se incorpora el envoltorio 100009 del grupo `replenishment-migrations` en la misma conexión e historial de los envoltorios anteriores. La migración ERP opcional tiene un historial independiente. Desplegar código PHP y activos de Vite correspondientes antes de habilitar el flujo. Verificar primero migración y reversión en una copia aislada; revertir la evolución elimina el seguimiento de D2.

En SQLite temporal, el recorrido de 1, 10, 25, 100 y 2001 relaciones sin ficha empleó respectivamente 0,09; 0,42; 0,75; 2,76 y 62,90 s, con 30 MiB de pico de asignación PHP del proceso. Las consultas propias fueron 30, 192, 462, 1812 y 36180, con una consulta al maestro temporal por relación. En un segundo escenario, 100 productos completos con movimientos, publicación y compra sugerida tardaron 15,32 s hasta terminar el cálculo. Al guardar se interrumpió la segunda línea por cambio de existencias. La prueba recalculó y confirmó ese producto, reanudó la construcción y verificó una sola propuesta con cien líneas. Estas mediciones corresponden a ejecución directa del servicio y dependen del equipo. Cada petición procesa un producto y la lectura histórica pagina sus movimientos. Durante esa lectura, el adaptador recupera por bloque únicamente las definiciones documentales pendientes y conserva las ya consultadas para el producto. Un producto excepcionalmente grande debe completar la lectura dentro del tiempo disponible para la petición; una interrupción obliga a repetir esa lectura si aún no existe publicación completa. La prueba de volumen no establece un plazo de operación para MAXCAM.

## E · Conciliación y consulta comercial

El dashboard lee los días de una única publicación con `CommercialSalesReport::breakdown()`. Unidades y documentos quedan en la fecha del evento. Un día verificado sin movimientos cuenta en el calendario aunque se desconozca su unidad. El subtotal observado de un período incompleto y su total definitivo se muestran por separado. Una devolución puede producir un neto negativo. La ficha del día de consulta, una presentación de compra y la continuidad asumida por la política no cambian esas observaciones.

La selección automática compara el perfil configurado y, cuando está disponible, la ficha verificada. La selección explícita recupera una fotografía histórica por producto y ubicación. Se presentan identificador, fechas de materialización y cobertura, unidad de la ficha y corte. Toda expansión semanal usa el mismo identificador y conserva los límites originales. Los días consultados fuera de la cobertura de una versión anterior permanecen desconocidos o excluidos por un corte documentado. Los consumidores con `SaleSource` conservan el recorrido de caché anterior.

La sección de planificación recupera el cálculo guardado, su versión, la política congelada, la señal, los bloques y el método aplicado. El modo asumido identifica al autorizante y los días incorporados. Cuando la evidencia carece de un motivo individual, la pantalla lo indica. El pronóstico FAC y los índices estacionales conservan sus auditorías y calidades. Para superponer observaciones al pronóstico se comprueban el período, la unidad, la señal, el perfil, las versiones y la publicación. Una decisión manual, una exclusión o una aprobación conservan su significado comercial.

### Muestras de conciliación

| Muestra controlada | Resultado contrastado |
| --- | --- |
| 018-013, del 01/01/2026 al 18/11/2026 exclusivo | 321 días, nueve movimientos en siete fechas, 314 días sin movimientos, FAC 8, NCC 1, NDC 0 y neto 7 UND. El Kardex separado contiene ENT 12 y saldo final 6. |
| Política sobre 018-013 | Con continuidad autorizada, la tasa neta es 7/321. En 90 y 180 días corresponden 630/321 y 1260/321. Disponible 6, seguridad cero y ausencia de entradas asumida producen necesidad central cero exploratoria. Una compra manual conserva su motivo y la referencia original. |
| Cambio de unidad | 270 UND netas y 18 CJA netas. Marzo conserva las dos unidades. La selección de CJA para pronóstico deja fuera cantidades UND incompatibles. |
| Descenso en bloques de 180 días | Con 300 y 120 UND, disponible 170 y 180 días de protección, el promedio general da demanda 210 y necesidad 40. El reciente da demanda 120 y necesidad cero. El conservador da 210 y exige revisión. |

Las muestras son reconstrucciones en SQLite temporal. El número de días sin movimiento depende de las fechas distintas de los documentos. No se verificó una recomendación vigente en el ERP. El [procedimiento consolidado de despliegue](migraciones.md#despliegue-del-ajuste-733) identifica el orden de instalación.
