<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Contracts\ModuleRegistryInterface;
use Modules\Core\Contracts\TenantProvisionerInterface;

class ModulosCommand extends Command
{
    protected $signature = 'core:modulos';

    protected $description = 'Lista os módulos, os serviços e os provisionadores e valida que são coerentes entre si.';

    public function handle(ModuleRegistryInterface $registo, TenantProvisionerInterface $provisionadores): int
    {
        $linhas = [];

        foreach ($registo->modulos() as $nome => $m) {
            $linhas[] = [$nome, $m['ativo'] ? 'sim' : 'não', $m['servico'] ?? '—', implode(', ', $m['requer']) ?: '—'];
        }

        $this->table(['Módulo', 'Ativo', 'Serviço', 'Requer'], $linhas);

        $this->newLine();
        $this->line('Provisionadores (chave -> serviço que os aciona):');

        foreach ($provisionadores->modules() as $chave) {
            $this->line("  {$chave} -> ".($provisionadores->servicoDe($chave) ?? '—'));
        }

        $problemas = $registo->validar();

        if ($problemas === []) {
            $this->newLine();
            $this->info('Módulos, serviços e provisionadores coerentes.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($problemas as $problema) {
            $this->error($problema);
        }

        return self::FAILURE;
    }
}
