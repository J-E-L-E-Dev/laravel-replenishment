<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Database\Migrations;
use Asisnet\Replenishment\Services\PersistenceConnection;
use Illuminate\Database\Migrations\Migration;
use LogicException;

/** Ejecuta el mismo esquema con el migrador estándar y rechaza destinos o historiales incompatibles. */
final class PublishedMigration extends Migration
{
    /**
     * Recibe la definición única de esquema, sin duplicar DDL.
     * @param class-string<Migration> $definition Clase de migración interna.
     */
    public function __construct(private string $definition) {}
    /**
     * Aplica la definición exclusivamente en el destino del registro estándar.
     * @return void
     * @throws LogicException Si se mezclan modalidades o conexiones.
     */
    public function up(): void {$this->execute('up');}
    /**
     * Revierte la definición sin tocar el historial dedicado.
     * @return void
     * @throws LogicException Si se mezclan modalidades o conexiones.
     */
    public function down(): void {$this->execute('down');}
    /**
     * Declara la conexión propia para las transacciones DDL de Laravel.
     * @return string Nombre Laravel configurado.
     */
    public function getConnection() {return app(PersistenceConnection::class)->getDefaultConnection();}
    /**
     * Comprueba el repositorio real antes de habilitar el esquema compartido.
     * @param string $direction Dirección interna up/down.
     * @return void
     * @throws \Throwable Si hay historial dedicado, destino incorrecto o falla el esquema.
     */
    private function execute(string $direction): void
    {
        $own=app(PersistenceConnection::class);
        $repository=app('migration.repository');
        if($repository->getConnection()->getName()!==$own->getDefaultConnection()){
            throw new LogicException('Use migrate --database con el nombre de REPLENISHMENT_DB_CONNECTION: tablas y registro deben compartir conexión.');
        }
        if($own->connection()->getSchemaBuilder()->hasTable('replenishment_migrations')){
            throw new LogicException('Esta instalación usa el historial dedicado. No se adoptará automáticamente: continúe con replenishment:migrate.');
        }
        $own->migrating(function()use($direction):void{(new $this->definition())->$direction();});
    }
}
