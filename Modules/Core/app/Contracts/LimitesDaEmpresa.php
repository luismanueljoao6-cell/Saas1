<?php

namespace Modules\Core\Contracts;

use Modules\Core\Models\Empresa;

/**
 * Contrato para saber os limites comerciais de uma empresa (nº de
 * utilizadores, faturas por mês...). O Core e a Faturação PERGUNTAM aqui;
 * quem sabe a resposta (hoje o módulo Subscrições, através do plano)
 * regista a implementação. Assim nenhum módulo depende diretamente de outro.
 */
interface LimitesDaEmpresa
{
    /**
     * @param  string  $chave  ex.: 'max_utilizadores', 'max_faturas_mes'
     * @return int|null null = sem limite
     */
    public function limite(Empresa $empresa, string $chave): ?int;
}
