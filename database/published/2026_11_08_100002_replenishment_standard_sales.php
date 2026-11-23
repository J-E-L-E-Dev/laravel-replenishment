<?php

declare(strict_types=1);
use Asisnet\Replenishment\Database\Migrations\PublishedMigration;
use Asisnet\Replenishment\Database\Migrations\AddObservedSales;
// Envoltorio estándar que reutiliza el esquema único.
return new PublishedMigration(AddObservedSales::class);
