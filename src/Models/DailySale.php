<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;
use Asisnet\Replenishment\Casts\ExactDecimal;
use InvalidArgumentException;

/** Caché diaria reconstruible; no representa hechos comerciales individuales ni históricos inmutables. */
final class DailySale extends ReplenishmentModel
{
    /** @var string Tabla propia de agregados. */
    protected $table = 'replenishment_daily_sales';
    /** @var array<string> Campos admitidos de la observación diaria. */
    protected $fillable = ['product_id', 'location_id', 'sales_date', 'gross_sales_units', 'returned_units', 'reversed_return_units', 'net_sales_units', 'event_count', 'base_unit', 'observation_status', 'quality_issues', 'inventory_quality_issues', 'observation_timezone', 'algorithm_version', 'calculated_at', 'created_at', 'updated_at'];
    /** @var array<string,string> Decimales exactos y causas JSON. */
    protected $casts = ['gross_sales_units' => ExactDecimal::class.':24,6', 'returned_units' => ExactDecimal::class.':24,6', 'reversed_return_units' => ExactDecimal::class.':24,6', 'net_sales_units' => ExactDecimal::class.':24,6', 'event_count' => 'integer', 'quality_issues' => 'array', 'inventory_quality_issues' => 'array', 'calculated_at' => 'immutable_datetime'];
    /** @var array<string> Identificadores opacos. */
    protected array $externalIds = ['product_id', 'location_id'];
    /** @var array<string> Campos obligatorios. */
    protected array $requiredFields = ['product_id', 'location_id', 'sales_date', 'observation_status', 'observation_timezone', 'algorithm_version', 'calculated_at'];
    /** @var array<string,int> Límites portables. */
    protected array $stringLengths = ['product_id' => 100, 'location_id' => 100, 'base_unit' => 100, 'observation_timezone' => 100, 'algorithm_version' => 100];
    /** @var array<string> Componentes no negativos. */
    protected array $nonNegativeFields = ['gross_sales_units', 'returned_units', 'reversed_return_units', 'event_count'];
    /** @var array<string> Conteos enteros. */
    protected array $integerFields = ['event_count'];
    /** @var array<string,array<string>> Estados de cobertura admitidos. */
    protected array $allowedValues = ['observation_status' => ['complete', 'incomplete']];

    /**
     * Valida mediante el modelo antes de escribir lotes de caché; no omite reglas de integridad.
     * @return array Atributos preparados para el upsert exclusivo de caché reconstruible.
     * @throws InvalidArgumentException Si la observación es incoherente.
     */
    public function validatedCacheAttributes(): array
    {
        $this->validatePersistence();
        return $this->getAttributes();
    }

    /**
     * Verifica fecha civil y separación entre desconocidos y ceros.
     * @return void
     * @throws InvalidArgumentException Si se publica un día parcial como completo.
     */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->sales_date);
        if ($date === false || $date->format('Y-m-d') !== $this->sales_date) {
            throw new InvalidArgumentException('La fecha de venta debe ser una fecha civil válida.');
        }
        foreach ([...\Asisnet\Replenishment\Services\ObservedDemandCalculator::QUANTITIES, 'event_count'] as $field) {
            if (($this->observation_status === 'complete') !== ($this->getAttribute($field) !== null)) {
                throw new InvalidArgumentException('Un día completo requiere cantidades; un día incompleto debe conservarlas en NULL.');
            }
        }
        if ($this->observation_status === 'complete' && !\Brick\Math\BigDecimal::of($this->gross_sales_units)->minus($this->returned_units)->plus($this->reversed_return_units)->isEqualTo($this->net_sales_units)) {
            throw new InvalidArgumentException('Los componentes diarios no coinciden con el neto firmado.');
        }
        if ($this->observation_status === 'complete' && $this->event_count > 0 && $this->base_unit === null) {
            throw new InvalidArgumentException('Un día con eventos completos requiere una unidad base verificada.');
        }
        if ($this->observation_status === 'complete' && $this->event_count === 0) {
            foreach (\Asisnet\Replenishment\Services\ObservedDemandCalculator::QUANTITIES as $field) {
                if (!\Brick\Math\BigDecimal::of($this->getAttribute($field))->isZero()) {
                    throw new InvalidArgumentException('Un día sin eventos no puede contener cantidades comerciales distintas de cero.');
                }
            }
        }
    }
}

