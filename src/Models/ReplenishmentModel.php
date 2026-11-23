<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Models;

use Asisnet\Replenishment\Casts\ExactDecimal;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Aplica conexión e integridad comunes a las escrituras individuales de Eloquent.
 *
 * SQL directo y actualizaciones masivas omiten estos eventos; no son API de dominio.
 */
abstract class ReplenishmentModel extends Model
{
    /** @var string Formato UTC con la precisión declarada en las migraciones. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var array<string> Atributos que deben estar presentes al guardar. */
    protected array $requiredFields = [];

    /** @var array<string> Identificadores externos opacos, nunca convertidos a números. */
    protected array $externalIds = [];

    /** @var array<string, int> Límites explícitos para cadenas portables. */
    protected array $stringLengths = [];

    /** @var array<string> Enteros de conteo o plazo no negativos. */
    protected array $integerFields = [];

    /** @var array<string> Decimales que no admiten cantidades negativas. */
    protected array $nonNegativeFields = [];

    /** @var array<string> Decimales estrictamente positivos. */
    protected array $positiveFields = [];

    /** @var array<string> Valores normalizados entre cero y uno. */
    protected array $ratioFields = [];

    /** @var array<string, array<string>> Dominios persistidos independientes del motor. */
    protected array $allowedValues = [];

    /** @var array<array<string>> Pares de inicio y fin exclusivos. */
    protected array $dateRanges = [];

    /** @var array<string> Atributos que no pueden cambiar después de crear el registro. */
    protected array $fixedFields = [];

    /** @var bool Impide actualizar o eliminar observaciones históricas mediante Eloquent. */
    protected bool $historical = false;

    /**
     * Utiliza exclusivamente el destino validado de la persistencia del paquete.
     *
     * @return string Nombre configurado o predeterminado de Laravel.
     * @throws InvalidArgumentException Si no existe la conexión configurada.
     */
    public function getConnectionName()
    {
        return app(PersistenceConnection::class)->getDefaultConnection();
    }

    /**
     * Impide redirigir modelos o relaciones hacia una conexión distinta.
     *
     * @param string|null $name Nombre solicitado por Eloquent.
     * @return static Modelo con el destino validado.
     * @throws InvalidArgumentException Si el nombre contradice la configuración propia.
     */
    public function setConnection($name)
    {
        app(PersistenceConnection::class)->assertConnection($name);

        return parent::setConnection($name);
    }

    /**
     * Rechaza identificadores externos numéricos antes de perder ceros iniciales.
     *
     * @param  string  $key  Nombre del atributo.
     * @param  mixed  $value  Valor suministrado por el consumidor.
     * @return static Modelo actualizado en memoria.
     *
     * @throws InvalidArgumentException Si un identificador no es una cadena opaca.
     */
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->externalIds, true) && $value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("{$key} debe ser una cadena opaca.");
        }

        if ($value !== null && ($this->getCasts()[$key] ?? null) === 'immutable_datetime') {
            $value = CarbonImmutable::parse($value, 'UTC')->utc();
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Instala las protecciones de escritura individual sin consultar tablas externas.
     */
    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists && $model->historical && $model->isDirty()) {
                throw new LogicException('El histórico es inmutable; cree una nueva observación.');
            }

            foreach ($model->fixedFields as $field) {
                if ($model->exists && $model->isDirty($field)) {
                    throw new LogicException("El atributo {$field} es inmutable; cree una nueva revisión.");
                }
            }

            $model->prepareForPersistence();
            $model->validatePersistence();
        });

        static::deleting(function (self $model): void {
            if ($model->historical) {
                throw new LogicException('No se permite eliminar históricos mediante el modelo.');
            }
        });
    }

    /**
     * Permite preparar datos explícitos antes de validar, sin calcular recomendaciones.
     */
    protected function prepareForPersistence(): void {}

    /**
     * Valida presencia, dominios, precisión, rangos e intervalos antes del INSERT o UPDATE.
     *
     * @throws InvalidArgumentException Si los atributos incumplen la integridad declarada.
     */
    protected function validatePersistence(): void
    {
        foreach ($this->getCasts() as $field => $cast) {
            if ($cast === 'array' && $this->getAttribute($field) !== null && ! is_array($this->getAttribute($field))) {
                throw new InvalidArgumentException("{$field} debe contener una estructura JSON de tipo array u objeto.");
            }
        }

        foreach ($this->requiredFields as $field) {
            if ($this->getAttribute($field) === null || $this->getAttribute($field) === '') {
                throw new InvalidArgumentException("El atributo {$field} es obligatorio.");
            }
        }

        foreach ($this->stringLengths as $field => $length) {
            $value = $this->getAttribute($field);
            if ($value !== null && (! is_string($value) || trim($value) === '' || mb_strlen($value) > $length)) {
                throw new InvalidArgumentException("Cadena inválida para {$field}.");
            }
        }

        foreach ($this->integerFields as $field) {
            $value = $this->getAttributes()[$field] ?? null;
            if ($value !== null && ((! is_int($value) && ! is_string($value))
                || ! preg_match('/^[0-9]+$/D', (string) $value)
                || strlen(ltrim((string) $value, '0')) > 10
                || (int) $value > 2147483647)) {
                throw new InvalidArgumentException("{$field} debe ser un entero no negativo de 32 bits.");
            }
        }

        foreach ($this->nonNegativeFields as $field) {
            if ($this->getAttribute($field) !== null && ExactDecimal::compare($this->getAttribute($field), '0') < 0) {
                throw new InvalidArgumentException("{$field} no puede ser negativo.");
            }
        }

        foreach ($this->positiveFields as $field) {
            if ($this->getAttribute($field) !== null && ExactDecimal::compare($this->getAttribute($field), '0') <= 0) {
                throw new InvalidArgumentException("{$field} debe ser mayor que cero.");
            }
        }

        foreach ($this->ratioFields as $field) {
            $value = $this->getAttribute($field);
            if ($value !== null && (ExactDecimal::compare($value, '0') < 0 || ExactDecimal::compare($value, '1') > 0)) {
                throw new InvalidArgumentException("{$field} debe estar entre cero y uno.");
            }
        }

        foreach ($this->allowedValues as $field => $values) {
            if (! in_array($this->getAttribute($field), $values, true)) {
                throw new InvalidArgumentException("Valor no permitido para {$field}.");
            }
        }

        foreach ($this->dateRanges as [$start, $end]) {
            if ($this->getAttribute($start) !== null && $this->getAttribute($end) !== null
                && $this->getAttribute($start) >= $this->getAttribute($end)) {
                throw new InvalidArgumentException("{$end} debe ser posterior a {$start}.");
            }
        }
    }
}
