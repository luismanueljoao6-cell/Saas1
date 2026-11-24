<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Services\CicloDeVidaEmpresaService;

class EliminarEmpresaCommand extends Command
{
    protected $signature = 'core:empresa-eliminar {empresa : ID da empresa} {--confirmar : Confirma a eliminação lógica}';

    protected $description = 'Elimina logicamente uma empresa: os dados ficam guardados e os utilizadores deixam de entrar (idempotente)';

    public function handle(CicloDeVidaEmpresaService $servico): int
    {
        $id = (string) $this->argument('empresa');

        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('O ID da empresa tem de ser um inteiro positivo.');

            return self::FAILURE;
        }

        if (! $this->option('confirmar')) {
            $this->error("Operação sensível: os utilizadores da empresa #{$id} deixam de entrar. Repete com --confirmar.");

            return self::FAILURE;
        }

        try {
            $alterou = $servico->eliminar((int) $id);
        } catch (ModelNotFoundException) {
            $this->error("Empresa #{$id} não encontrada.");

            return self::FAILURE;
        }

        $this->info($alterou ? "Empresa #{$id} eliminada logicamente." : "Empresa #{$id} já estava eliminada.");

        return self::SUCCESS;
    }
}
