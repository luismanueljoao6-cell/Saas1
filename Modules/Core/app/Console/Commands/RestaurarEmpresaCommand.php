<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Services\CicloDeVidaEmpresaService;

class RestaurarEmpresaCommand extends Command
{
    protected $signature = 'core:empresa-restaurar {empresa : ID da empresa}';

    protected $description = 'Restaura uma empresa eliminada logicamente: os utilizadores voltam a entrar (idempotente)';

    public function handle(CicloDeVidaEmpresaService $servico): int
    {
        $id = (string) $this->argument('empresa');

        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('O ID da empresa tem de ser um inteiro positivo.');

            return self::FAILURE;
        }

        try {
            $alterou = $servico->restaurar((int) $id);
        } catch (ModelNotFoundException) {
            $this->error("Empresa #{$id} não encontrada.");

            return self::FAILURE;
        }

        $this->info($alterou ? "Empresa #{$id} restaurada." : "Empresa #{$id} já estava ativa.");

        return self::SUCCESS;
    }
}
