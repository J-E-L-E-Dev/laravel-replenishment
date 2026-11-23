# Migraciones

La entrada `2026_11_07_000001_create_replenishment_tables.php` instancia la migración del paquete, que crea doce tablas con claves foráneas exclusivamente internas. La ruta sólo la utiliza el migrador explícito del paquete; el proveedor no la añade al migrador global. No copiar estas entradas internas al consumidor. La modalidad estándar optativa utiliza los envoltorios separados de database/published y exige una instalación nueva sin historial dedicado.

La conexión se selecciona mediante `replenishment.connection` y REPLENISHMENT_DB_CONNECTION; null usa la predeterminada de Laravel. `replenishment:migrate` fija el mismo destino para DDL, transacciones de esquema y el registro dedicado `replenishment_migrations`. `replenishment:migrate --rollback` revierte el último lote propio y conserva ese registro, eliminando sólo sus entradas revertidas. Las instrucciones están en [integración](../../docs/integracion.md).

La reversión elimina las tablas propias en orden inverso; destruye sus históricos y sólo se verificó en bases temporales. No ejecutar migraciones ni reversiones sobre bases reales durante esta fase.

La migración 2026_11_08_000002_add_observed_sales crea la caché diaria y amplía métricas con reversión y auditoría observada. Sólo se ejecuta mediante replenishment:migrate, conservando tablas y registro en la conexión propia. No ha sido aplicada a bases reales durante la Fase 4.1.

## Ampliación de Fase 6.1

2026_11_09_000003_add_supplier_lead_time_evidence añade destino lógico nullable y auditoría de evidencia a métricas de proveedor. No asigna destinos a históricos previos. Se ejecuta sólo mediante replenishment:migrate; --rollback del lote elimina los dos campos y el índice nuevos. No se aplicó en bases reales. Véase ../docs/plazos-proveedores.md.

## Ajuste 7.2.1

2026_11_10_000004_create_supplier_imports añade únicamente el control durable de cargas. No se aplicó en consult-web. Los envoltorios publicables reutilizan todas las definiciones y verifican destino e historial. Consulte [modalidades y actualización segura](../../docs/migraciones.md).
