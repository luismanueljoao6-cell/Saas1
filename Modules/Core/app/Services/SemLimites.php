<?php

namespace Modules\Core\Services;

use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;

/**
 * Implementação por omissão: sem limites. É substituída pelo módulo
 * Subscrições quando este está instalado.
 */
class SemLimites implements LimitesDaEmpresa
{
    public function limite(Empresa $empresa, string $chave): ?int
    {
        return null;
    }
}
