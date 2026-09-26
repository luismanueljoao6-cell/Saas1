<?php

namespace Modules\Core\Repositories\Contracts;

use Modules\Core\Models\Empresa;

interface EmpresaRepositoryInterface
{
    public function encontrarPorId(int $id): ?Empresa;

    public function encontrarPorNif(string $nif): ?Empresa;

    /**
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(Empresa $empresa, array $dados): Empresa;
}
