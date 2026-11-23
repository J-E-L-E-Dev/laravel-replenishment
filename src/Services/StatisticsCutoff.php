<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use DateTimeImmutable;
use DateTimeZone;

/** Resuelve la extensión optativa del maestro externo; nunca usa la conexión propia por omisión. */
final class StatisticsCutoff
{
    /** Recibe infraestructura del consumidor sin crear conexiones. @param Repository $config Configuración explícita. @param DatabaseManager $database Conexiones existentes. */
    public function __construct(private Repository $config,private DatabaseManager $database) {}
    /**
     * Valida identificadores y comprueba la existencia de la tabla en la conexión configurada.
     *
     * @return array{0:Connection,1:string,2:string} Conexión, tabla y columna.
     * @throws InvalidArgumentException Ante configuración incompleta o insegura.
     * @throws LogicException Si falta la tabla o el motor carece de inspección compatible.
     */
    public function target(): array
    {
        $settings = $this->config->get('replenishment.statistics_cutoff', []);
        $name = $settings['connection'] ?? null;
        $table = $settings['table'] ?? null;
        $column = $settings['column'] ?? null;
        if (!is_string($name) || $name === '' || !is_array($this->config->get('database.connections.'.$name))
            || !is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $table)
            || !is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) {
            throw new InvalidArgumentException('Configure conexión Laravel existente, tabla y columna válidas para statistics_cutoff.');
        }
        $db = $this->database->connection($name);
        if (!in_array($db->getDriverName(), ['sqlite', 'mysql', 'sqlsrv'], true)) {
            throw new LogicException('El motor no tiene inspección DATE NULL implementada para el corte estadístico.');
        }

        // En Laravel 10 hasTable compara nombres simples y pierde el esquema SQL Server.
        $exists = $db->getDriverName() === 'sqlsrv'
            ? $db->selectOne("SELECT OBJECT_ID(?, 'U') AS table_id", [$table])->table_id !== null
            : $db->getSchemaBuilder()->hasTable($table);
        if (!$exists) {
            throw new LogicException('No existe la tabla configurada del corte estadístico.');
        }

        return [$db, $table, $column];
    }

    /**
     * Verifica el tipo DATE y su nulabilidad en el esquema explícito del maestro.
     *
     * @param Connection $db Conexión externa configurada.
     * @param string $table Tabla validada.
     * @param string $column Columna validada.
     * @return bool Verdadero si la columna existe y es compatible, falso si falta.
     * @throws LogicException Ante una columna existente con otro tipo o nulabilidad.
     */
    private function compatible(Connection $db, string $table, string $column): bool
    {
        if ($db->getDriverName() === 'sqlite') {
            $row = $db->selectOne('SELECT type, "notnull" AS required FROM pragma_table_info(?) WHERE name = ?', [$table, $column]);
            $type = $row->type ?? null;
            $nullable = !(bool) ($row->required ?? true);
        } else {
            $parts = explode('.', $table);
            $name = array_pop($parts);
            $schema = $parts[0] ?? ($db->getDriverName() === 'sqlsrv' ? 'dbo' : $db->getDatabaseName());
            $row = $db->table('INFORMATION_SCHEMA.COLUMNS')->where('TABLE_SCHEMA', $schema)
                ->where('TABLE_NAME', $name)->where('COLUMN_NAME', $column)->first(['DATA_TYPE', 'IS_NULLABLE']);
            $type = $row->DATA_TYPE ?? null;
            $nullable = ($row->IS_NULLABLE ?? null) === 'YES';
        }
        if ($row === null) {
            return false;
        }
        if (strtolower((string) $type) !== 'date' || !$nullable) {
            throw new LogicException('La columna de corte existente debe ser DATE NULL; no se modifica automáticamente.');
        }

        return true;
    }
    /** Agrega la columna solamente por ejecución explícita de la migración publicada. @return void @throws LogicException Si la configuración está deshabilitada o es incompatible. */
    public function install(): void
    {
        if($this->config->get('replenishment.statistics_cutoff.enabled',false)!==true){throw new LogicException('Habilite expresamente statistics_cutoff antes de ejecutar su migración optativa.');}
        [$db,$table,$column]=$this->target();if($this->compatible($db,$table,$column)){return;}
        \Illuminate\Support\Facades\Schema::connection($db->getName())->table($table,function(\Illuminate\Database\Schema\Blueprint $blueprint)use($column):void{$blueprint->date($column)->nullable();});
    }
    /** Lee un corte opcional sin tocar la columna cuando está deshabilitado. @param string $product Identidad textual. @param string $key Columna de identidad del adaptador. @return string|null Fecha civil o ausencia de corte. @throws LogicException Si falta instalación o la fecha es inválida. */
    public function forProduct(string $product,string $key): ?string
    {
        $enabled=$this->config->get('replenishment.statistics_cutoff.enabled',false);if(!is_bool($enabled)){throw new InvalidArgumentException('statistics_cutoff.enabled debe ser booleano.');}if(!$enabled){return null;}
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$key)){throw new InvalidArgumentException('Identificador de producto inválido.');}
        [$db,$table,$column]=$this->target();if(!$this->compatible($db,$table,$column)){throw new LogicException('Instalación pendiente: falta la columna DATE NULL del corte estadístico. Publique y revise la migración optativa.');}
        $value=$db->table($table)->where($key,$product)->value($column);if($value===null){return null;}$date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);if(!$date||$date->format('Y-m-d')!==$value){throw new LogicException('Fecha de corte estadístico inválida.');}return $value;
    }
    /** Reduce el inicio estadístico sin alterar existencias ni documentos de conciliación. @param DateTimeImmutable $requested Inicio solicitado. @param string|null $cutoff Corte inclusivo. @param string $timezone Zona civil. @return DateTimeImmutable Inicio efectivo; el consumidor debe comprobarlo frente al fin. @throws InvalidArgumentException Ante fecha inválida. */
    public function effectiveStart(DateTimeImmutable $requested,?string $cutoff,string $timezone): DateTimeImmutable
    {
        if($cutoff===null){return $requested;}$date=DateTimeImmutable::createFromFormat('!Y-m-d',$cutoff,new DateTimeZone($timezone));if(!$date||$date->format('Y-m-d')!==$cutoff){throw new InvalidArgumentException('Corte estadístico inválido.');}return max($requested,$date->modify('+1 day'));
    }
}
