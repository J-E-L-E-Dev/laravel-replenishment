<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Contracts;

/** Fuente de candidatos por proveedor, sin afirmar preferencias ni conversiones comerciales. */
interface SupplierProductSource
{
    /**
     * Lee un bloque ordenado por código, con cursor exclusivo y sin duplicados.
     * @param string $supplierId Identidad canónica verificada.
     * @param string|null $after Último código confirmado, o NULL para iniciar.
     * @param int $limit Máximo de registros solicitado, entre uno y cien.
     * @return array<string> Códigos originales; menos que el límite indica fin observado.
     * @throws \RuntimeException Si falla la fuente; nunca debe devolver ausencia ficticia.
     */
    public function productsFor(string $supplierId,?string $after,int $limit): array;
}
