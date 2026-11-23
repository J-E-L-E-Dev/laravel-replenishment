<?php

declare(strict_types=1);

return [
    // La publicación limita también los parámetros por sentencia según las columnas del detalle.
    'statistics_batch_size' => env('REPLENISHMENT_STATISTICS_BATCH_SIZE', 100),
    'performance_log' => env('REPLENISHMENT_PERFORMANCE_LOG', false),

    // Perfil comercial del consumidor; el paquete no excluye documentos por defecto.
    'sales' => ['document_exclusions' => []],
    // Extensión externa optativa, con destino explícito y migración separada.
    'statistics_cutoff' => ['enabled' => false, 'connection' => null, 'table' => null, 'column' => 'corte_de_estadisticas'],
    // null utiliza la conexión predeterminada; nunca abre ni migra una base por sí mismo.
    'connection' => env('REPLENISHMENT_DB_CONNECTION'),

    // Sin supuestos comerciales: los parámetros deben suministrarse antes de activar una política.
    'policy_defaults' => [
        'analysis_days' => null,
        'target_coverage_days' => null,
        'service_level' => null,
        'default_lead_time_days' => null,
        'minimum_history_days' => null,
        'seasonal_minimum_years' => null,
        'forecast_horizon_days' => null,
    ],

    // Umbrales exploratorios explícitos; no son decisiones comerciales ni confianza calibrada.
    'seasonality' => [
        'history_years' => 5,
        'minimum_years' => 3,
        'high_index' => '1.20',
        'low_index' => '0.80',
        'recurrence' => '0.666666',
        'maximum_spread' => '0.50',
        'outlier_factor' => '3',
        'alpha' => '0.05',
        'minimum_active_days' => 12,
    ],

    // Parámetros estadísticos auditados; no representan decisiones de compra ni confianza calibrada.
    'forecast' => [
        'horizon' => 3, 'window' => 12, 'minimum_training' => 3,
        'minimum_selection' => 6, 'validation_origins' => 3, 'maximum_origins' => 24,
        'alphas' => ['0.2', '0.5', '0.8'], 'recent_weight' => '1', 'damping' => '0.8',
        'maximum_trend_factor' => '2', 'intermittent_fraction' => '0.4', 'minimum_positive_months' => 3,
        'improvement' => '0.05', 'maximum_wape' => '0.50', 'maximum_bias' => '0.25',
        'maximum_cache_age_days' => 30,
    ],

    // Criterios de evidencia, nunca plazos comerciales deducidos de órdenes o presentaciones.
    'supplier_lead_time' => [
        'minimum_orders' => 3, 'minimum_coverage' => '0.50', 'maximum_age_days' => 365,
        'maximum_orders' => 250, 'historical_measure' => 'complete_receipt', 'policy_id' => null,
    ],

    // Cada adaptador es una clase del consumidor; null significa integración pendiente.
    'adapters' => [
        'product_costs' => null,
        'products' => null,
        'inventory' => null,
        'movements' => null,
        'reservations' => null,
        'commercial_history' => null,
        'sales' => null,
        'purchases' => null,
        'purchase_history' => null,
        'suppliers' => null,
    ],
];
