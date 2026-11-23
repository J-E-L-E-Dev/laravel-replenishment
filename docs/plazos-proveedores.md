# Proveedores y tiempos de abastecimiento — Fase 6.1

## Alcance y evidencia

El motor reutiliza PurchaseHistorySource y PurchaseReconciler. No contiene consultas ERP, no normaliza nuevamente signos y no confirma mercancía pendiente. Una observación identifica orden, producto, proveedor, destino, fechas, primera recepción, cantidad de vínculos y huella de sus identidades. Conserva motivos de exclusión y limitaciones originales.

SupplierLeadTimeAnalyzer distingue:

- Vínculo documental verificado: referencia, producto, proveedor, destino, fechas y unidades compatibles según el conciliador.
- Primera recepción **observada**: primera entrada positiva verificable dentro de los movimientos disponibles. Puede calcular días sin asegurar que no existan recepciones anteriores omitidas.
- Primera recepción **certificada**: además requiere cobertura documental externa comprobada desde la emisión hasta el corte.
- Cobertura observada de cantidades: el neto conciliado coincide con lo pedido; no acredita completitud histórica ni cierre comercial.
- Recepción completa **certificada**: requiere cobertura anterior, evidencia externa específica y fecha coincidente con observedCompletionAt, cantidades compatibles y ausencia de devoluciones/reversiones. No se deduce del estado verified ni confirma cierre comercial.

LeadTimeCoverageData representa una verificación externa **ya efectuada** de todas las recepciones y sus unidades comparables para el alcance, con referencias auditables. Escribir una referencia no demuestra esa verificación. El núcleo valida coherencia, alcance y fechas; no puede autenticar un documento externo. OrbisNET y la CLI actual **no generan esta atestación**. Quien integre una fuente certificada puede entregarla expresamente al analizador o a SupplierLeadTimeQuery mediante coverage; nunca debe fabricarla a partir de una consulta exitosa.

Una orden sin recepción no aporta duración cero ni se clasifica como incumplimiento. Órdenes ambiguas, referencias rechazadas y fechas incompatibles quedan excluidas. Varias líneas del mismo producto conservan granularidad orden/producto; no se asignan recepciones arbitrariamente a líneas. Varias recepciones siguen siendo una observación por orden/producto/destino. Las identidades duplicadas de orden o la reutilización de una recepción entre órdenes invalidan el lote.

## Fechas y estadísticas

Se utilizan días calendario en America/Caracas, con límites civiles [from, until): órdenes emitidas dentro de la ventana y movimientos anteriores a until. No se inventan horas. Una recepción el mismo día da cero observado real; el cambio de año conserva la diferencia civil. La fecha esperada nunca sustituye una recepción. Si cabecera y líneas discrepan en su fecha esperada, la diferencia permanece NULL; una estimación anterior a la emisión tampoco se usa. La diferencia es recepción menos estimación: negativa significa anterior a lo estimado, sin certificar cumplimiento.

Cada serie mantiene número de observaciones, mínimo, media, máximo, desviación poblacional, cobertura muestral y última recepción. La desviación usa sqrt(sum(x²)/n - media²); se evalúa algebraicamente con Brick Math, sin float. Varianza a 18 decimales, raíz a 12 y presentación a 6 con HALF_UP. Con una sola observación la desviación permanece NULL; sin observaciones tampoco hay plazos. Los conteos de una lectura completa pueden ser cero; los de una lectura fallida son NULL.

Configuración predeterminada en replenishment.supplier_lead_time:

```php
'supplier_lead_time' => [
    'minimum_orders' => 3,
    'minimum_coverage' => '0.50',
    'maximum_age_days' => 365,
    'maximum_orders' => 250,
    'historical_measure' => 'complete_receipt',
    'policy_id' => null,
],
```

Una serie histórica utilizable requiere evidencia certificada, al menos tres órdenes independientes, cobertura de al menos 0,50 y última recepción no anterior a 365 días respecto a la evaluación. La cobertura es observaciones elegibles / órdenes del proveedor evaluadas, **no** cumplimiento de entregas ni confianza. El umbral se compara sin redondear la fracción. La actualidad se refiere a la última recepción; no afirma que todas las observaciones sean recientes. El período y las fechas conservados permiten restringir la ventana explícitamente. El máximo de órdenes cuenta también otros proveedores recorridos por el contrato: superar el límite invalida todo el lote, no publica una muestra truncada.

Calidades: insufficient si no hay evidencia o la lectura falló; exploratory si hay observaciones que no cumplen los criterios; usable sólo cuando todos se cumplen. Toda primera recepción meramente observada es exploratoria, aunque la muestra sea grande. Una excepción o duplicado invalida totales y observaciones del lote. Órdenes recientes pueden estar censuradas por el corte; los criterios no equivalen a significación estadística. confidence, quantity_fulfillment, date_fulfillment y commercial_closure permanecen NULL.

## Resolución y vigencia

SupplierLeadTimeResolver selecciona una sola fuente:

1. Manual explícito en una relación ProductSupplier activa, incluido cero configurado conscientemente para entrega el mismo día.
2. Historial certificado utilizable para producto/proveedor/destino y medida elegida.
3. Policy activa elegida por policy_id, con default_lead_time_days no NULL; después, replenishment.policy_defaults.default_lead_time_days explícito.
4. unknown, con plazo NULL.

El manual tiene alcance general producto-proveedor. No se presenta como medición de la sucursal ni recibe confianza estadística. Permanece vigente hasta modificarlo, retirarlo o desactivar la relación; updated_at no prueba renovación contractual. No existe caducidad contractual automática en este modelo. Un valor manual activo prevalece sobre el histórico sin promediar fuentes. Una política no se elige por ser la primera de la tabla. Un plazo exploratorio nunca se selecciona automáticamente.

Por defecto sólo es elegible complete_receipt. first_receipt debe seleccionarse expresamente y se acompaña de advertencia: no representa abastecimiento completo. La resolución conserva por separado tamaño y variabilidad de la muestra histórica y los de la fuente seleccionada; los últimos son NULL para manual y política.

Ejemplo de configuración comercial explícita, una vez instalada la persistencia propia:

```php
$relation = ProductSupplier::query()->where('product_id', 'PRODUCTO')
    ->where('supplier_id', 'PROVEEDOR')->firstOrFail();
$relation->manual_lead_time_days = 7;
$relation->save();
```

El ejemplo no se ejecutó en datos reales. Usar NULL para revocar el plazo, no cero. No se cambia SupplierPresentation, unidad, empaque ni conversión para registrar días. El plazo no demuestra cantidad pendiente ni autoriza generar pedidos.

## Persistencia y migración

SupplierLeadTimeStore añade SupplierMetric inmutable, nunca actualiza una métrica existente. Requiere relación producto-proveedor coincidente y al menos una recepción observada documentalmente válida con lectura finalizada. Puede guardar un diagnóstico exploratorio identificándolo como tal; no lo convierte en plazo operativo.

La migración propia 2026_11_09_000003 añade location_id nullable y lead_time_evidence (JSON en texto nullable), además del índice por relación/destino/fecha. Es necesaria para impedir mezcla de destinos y conservar calidad, observaciones, referencias y opciones sin introducir otra tabla. Las columnas numéricas existentes representan **primera recepción observada**, estimation_source=observed_first_receipt. verified_orders cuenta órdenes con vínculo válido, no órdenes comercialmente cerradas. Los plazos certificados de primera/completa y sus calidades se guardan separados en lead_time_evidence.summary. Los antiguos registros conservan NULL en ubicación y evidencia: no se atribuyen a ningún destino ni se seleccionan por el resolver.

Publicación dentro de PersistenceConnection, versión supplier-lead-time-1.0, con período, evaluación y evidencia de todas las observaciones del lote acotado. Una excepción después del INSERT revierte la escritura. Los manuales y predeterminados nunca se guardan como promedios históricos. Todas las relaciones Eloquent conservan la conexión propia.

Sólo replenishment:migrate gestiona esquema y replenishment_migrations en la misma conexión. No se ejecutó ninguna migración real. El rollback del lote evolutivo elimina los dos atributos e índice nuevos, con pérdida deliberada de esa evidencia al solicitarlo, pero conserva filas previas y otras columnas. Realizar respaldo antes de un rollback operativo. El migrador general sigue sin incluir las migraciones del paquete.

## Diagnóstico del consumidor

```sh
php artisan replenishment:supplier-lead-time PRODUCTO PROVEEDOR --from=2026-01-01 --until=2026-11-09 --location=orbis-global
```

Devuelve resolution e historical con resumen y hasta diez muestras de exclusión, sin listar movimientos. Código 0 significa diagnóstico finalizado, incluso con plazo desconocido; código 1 señala lectura incompleta, parámetros inválidos o publicación fallida. Por defecto sólo lee. Sin tablas propias continúa la historia y advierte que no están disponibles manuales almacenados. Una conexión propia inaccesible tampoco se interpreta como plazo cero. No crea relaciones, conexiones ni migraciones.

--persist exige esquema propio actualizado y relación existente, y sólo añade métricas históricas respaldadas. Una ausencia de evidencia impide publicar aunque haya un plazo manual. El adaptador y su conexión ERP son independientes de REPLENISHMENT_DB_CONNECTION.

## Verificación y límites

Pruebas focalizadas en PHP 8.2.28: paquete Laravel 12 con SQLite y consumidor Laravel 10 con dos SQLite independientes. Se ejercitan primera/parcial/completa, cero, cambio de año, referencias y ámbitos inválidos, fechas ausentes/incompatibles, líneas múltiples, desviación, suficiencia, frescura, precedencia, política, ausencia de fuentes, fallos tardíos, destinos, inmutabilidad, rollback y evolución de esquema. No se repitió la matriz completa ni se amplió Composer. El lint PHP 8.1 verifica sintaxis; no equivale a ejecución de toda la matriz.

La lectura SQL Server real del 09-11-2026 contrastó 800/00001290 (546 líneas) y tres órdenes recientes: 800/00001330 (16), 800/00001329 (24), 800/00001328 (17). Para el primer producto de cada orden no hubo recepciones vinculables. La candidata previa 500/00025344 conserva dos cabeceras ENT sin referencia de orden y ocho detalles. La búsqueda documental acotada a veinte cabeceras con prefijo 800 no devolvió candidatas. No prueba ausencia universal de recepciones.

El motor nuevo sobre el producto/proveedor de 800/00001330, entre 01-10-2026 y 09-11-2026, terminó con una orden evaluada, cero aceptadas y una excluida; plazos NULL, calidad insuficiente. Ningún caso real certificó plazo hasta primera ni completa. Estos contrastes SELECT no verifican persistencia, DDL ni transacciones en SQL Server 2017. La configuración manual y las entregas certificadas se validaron únicamente con datos ficticios.

Se conservan las restricciones de históricos, reservas, devoluciones y pronósticos de 5.2. No se implementan reposición, stock de seguridad, compras sugeridas ni Fase 6.2.

Resultados focalizados: SupplierLeadTimeTest, 13 pruebas y 98 aserciones; conciliador existente y reversión de ventas observadas, 5 pruebas y 24 aserciones; consumidor (filtro LeadTime), 2 pruebas y 34 aserciones. Total: 20 pruebas y 156 aserciones satisfactorias. Los 17 archivos PHP nuevos/modificados comprobados pasan lint con PHP 8.1.33. No se repitieron suites completas ni la matriz Laravel 10/11/12.
