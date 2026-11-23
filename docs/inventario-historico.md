# Reconstrucción y calidad del inventario histórico — Fase 4.2

## Alcance y componentes

`StockHistoryAnalyzer` evalúa `InventoryMovementSource` y conserva intactos sus `InventoryMovementData`. Reutiliza `HistoricalStockInspector` y el calendario de `ObservedDemandCalculator`. `StockHistoryQuery` coordina adaptadores existentes y permite contrastar ventas del origen o leer `ObservedDemandStore`. No persiste resultados ni crea tablas o migraciones.

`StockObservationData` separa saldo observado y calidad. `StockIntervalData` representa tramos, causas y evidencia; `StockHistoryData` devuelve resumen, calendario y hasta 50 muestras de cada clase. `audit()` excluye movimientos individuales. Se conserva el contrato de movimientos existente, sin añadir consultas ERP al núcleo.

## Semántica y clasificación

- **Existencia observada:** saldo puntual registrado, positivo, no positivo o desconocido. Un saldo negativo se conserva. Su signo no acredita continuidad.
- **Existencia reconstruida:** saldo que puede sostenerse entre dos anclajes cuando se demuestra la totalidad de cambios físicos, unidades y orden temporal. No se corrigen saldos para hacerlos coincidir.
- **Continuidad verificada:** requiere saldos y efectos conocidos, misma unidad base, conciliación exacta, horas fiables, ausencia de ambigüedades y cobertura documental demostrada.
- **Agotamiento potencial:** tramo entre observaciones compatibles cuyo punto inicial es no positivo. Es un conteo diagnóstico, no una duración estimada ni un día agotado.
- **Agotamiento confirmado:** continuidad verificada no positiva durante el intervalo. Sólo se acredita un día cuando toda su duración civil está cubierta, sin redondear huecos ni microsegundos.
- **Disponibilidad comercial histórica:** permanece NULL. Las reservas actuales no reconstruyen compromisos antiguos. Tampoco se estima demanda perdida.

`consistent` expresa coherencia dentro del alcance comprobado; una observación coherente no hace consistente su intervalo. `ambiguous` señala discrepancias, unidades cambiantes u orden incierto; `incomplete` indica datos o anclajes ausentes; `unverified` conserva tramos sin cobertura u horas demostradas. Los extremos diarios son nominales cuando falta hora: no representan instantes reales de transacción.

`InventoryCoverageData` permite aportar al motor evidencia externa previamente verificada, con producto, ubicación, unidad, intervalo y referencia documental. Quien lo construye debe haber comprobado que comprende TODOS los cambios físicos y su secuencia. Una referencia textual no demuestra esa comprobación. Ningún adaptador Orbis ni comando de esta fase lo proporciona; no existe opción para asumir cobertura. Sin esta evidencia, incluso una secuencia aritméticamente coherente conserva intervalos sin confirmar.

No se extrapola antes del primer punto, después del último ni a través de discrepancias. Los días sin movimientos permanecen desconocidos salvo cobertura verificada entre anclajes. `confirmed_stockout_days` cuenta únicamente el subconjunto de días completamente acreditados, y permanece NULL si no hay días clasificables. `total_stockout_days` permanece NULL si queda cualquier día desconocido. Cero días acreditados no significa cero agotamientos reales.

## Lectura, calendario y errores

Se usan días completos, cortes `[from, until)` y zona operativa; máximo diez años por consulta. Las cantidades y duraciones utilizan aritmética exacta. La lectura es secuencial, con índice externo de identidades reutilizado y muestras acotadas. Un duplicado, mezcla de producto/ubicación, fecha fuera de ventana, orden decreciente o fallo tardío invalida los totales y elimina las muestras parciales. Los errores conservan una causa controlada sin revelar SQL.

`reading_status=complete` significa que terminó la lectura, no que el libro documental esté completo. Los conteos de causas de observaciones y tramos se presentan separados: una causa puede afectar varios tramos y no debe sumarse como si fueran movimientos distintos. Una regresión de identidad documental es una señal de orden dudoso, no prueba concluyente de retroactividad ni una hora real.

## Contraste comercial

Puede recibirse `ObservedDemandData` del mismo producto, ubicación, zona y ventana. Se comparan sólo días comerciales completos. Las señales identifican ventas en días con saldos no positivos, ventas con continuidad física desconocida y días sin ventas con stock desconocido. La coincidencia diaria no prueba simultaneidad; tampoco se certifica una instantánea común entre consultas. Las ventas no se modifican y no se calculan demanda potencial, pronósticos ni reposición.

## Integración y verificación

El consumidor incorpora `replenishment:stock-history`; véase su documentación de pilotos para resultados reales. El núcleo no necesita nuevas dependencias ni conexiones. El contraste opcional de caché utiliza exclusivamente la persistencia propia ya configurada; el diagnóstico físico no escribe ni consulta reservas.

Las pruebas focalizadas cubren puntos positivos/cero/negativos, transiciones, ajustes firmados, discrepancias, unidades, ausencia de horas/anclajes, múltiples eventos diarios, orden inválido, identidades repetidas, fuente fallida, separación de alcances, muestras acotadas y cruce comercial. Los casos sintéticos con cobertura verificada comprueban días confirmables; no constituyen evidencia del ERP.

Resultado focalizado: paquete 17 pruebas / 80 aserciones; consumidor 2 pruebas / 40 aserciones. Total 19 pruebas / 120 aserciones satisfactorias. PHP 8.2.28; PHPUnit 11.5.57 en Laravel 12 y PHPUnit 10.5.29 en Laravel 10. Sintaxis comprobada con PHP 8.1.33, sin repetir ni ampliar la matriz. Seis pilotos SQL Server (1880 observaciones) no aportaron continuidad suficiente para confirmar días agotados; véase la evidencia detallada del consumidor. No hubo migraciones ni escrituras reales.
