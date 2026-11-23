<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

use Illuminate\Contracts\Config\Repository;

/** Identifica las reglas de extracción por valores públicos, sin fechas de ejecución ni credenciales. */
final class CommercialProfile
{
    public const VERSION = 'observed-sales-by-unit-2.0';

    /** @param Repository $config Configuración semántica del consumidor. */
    public function __construct(private Repository $config) {}

    /** Determina si el consumidor eligió explícitamente la nueva fuente. @return bool Selección del contrato opcional. */
    public function enabled(): bool
    {
        return $this->config->get('replenishment.adapters.commercial_history') !== null;
    }

    /**
     * Forma el perfil reproducible, incluida la revisión declarada de reglas particulares del adaptador.
     * @param string $product Producto opaco.
     * @param string $location Ámbito lógico.
     * @param string $timezone Zona civil.
     * @param string|null $unit Unidad comercial actual conocida.
     * @param string|null $cutoff Fecha de corte aplicada.
     * @return array Parámetros públicos del cálculo.
     */
    public function describe(string $product, string $location, string $timezone, ?string $unit, ?string $cutoff): array
    {
        $rules = new DocumentExclusions($this->config->get('replenishment.sales.document_exclusions', []));
        $canonical = [];
        foreach ($rules->rules as $rule) {
            $value = $rule['value'];
            if (is_array($value)) {
                $value = array_values(array_unique($value, SORT_STRING));
                sort($value, SORT_STRING);
            }
            $entry = ['operator' => $rule['operator'], 'value' => $value];
            $canonical[json_encode($entry, JSON_THROW_ON_ERROR)] = $entry;
        }
        ksort($canonical, SORT_STRING);
        $rules = new DocumentExclusions(array_values($canonical));
        $settings = $this->config->get('replenishment.statistics_cutoff', []);
        return [
            'source_scope' => $this->config->get('replenishment.sales.source_scope', 'application'),
            'product' => $product, 'location' => $location, 'timezone' => $timezone,
            'source' => $this->config->get('replenishment.adapters.commercial_history'),
            'product_source' => $this->config->get('replenishment.adapters.products'),
            'normalizer' => $this->config->get('replenishment.sales.normalizer_version', 'commercial-history-1'),
            'aggregator' => self::VERSION, 'current_unit' => $unit, 'cutoff' => $cutoff,
            'cutoff_settings' => ($settings['enabled'] ?? false)
                ? array_intersect_key($settings, array_flip(['enabled','connection','table','column'])) : ['enabled' => false],
            'exclusions' => $rules->rules, 'exclusions_hash' => $rules->fingerprint(),
        ];
    }

    /** Calcula SHA-256 sobre JSON canónico. @param array $profile Datos públicos. @return string Huella hexadecimal. */
    public static function hash(array $profile): string
    {
        return hash('sha256', json_encode(self::canonical($profile), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Ordena claves de mapas conservando el orden de listas. @param array $values Mapa o lista. @return array Copia canónica. */
    private static function canonical(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values, SORT_STRING);
        }
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::canonical($value);
            }
        }
        return $values;
    }
}
