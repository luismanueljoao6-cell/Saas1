<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantProvisionerRegistry;
use Modules\Core\Support\Servico;
use Throwable;

class ProvisionarServicosCommand extends Command
{
    protected $signature = 'core:provisionar-servicos {--empresa= : ID da empresa (por omissão, todas)}';

    protected $description = 'Cria ou repara papéis e permissões dos serviços ativos de cada empresa (idempotente)';

    public function handle(TenantProvisionerRegistry $registry): int
    {
        $empresas = Empresa::query()
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        $falhas = 0;

        foreach ($empresas as $empresa) {
            try {
                $registry->provision(
                    $empresa->id,
                    $empresa->servicosAderidos()->map(fn (Servico $servico) => $servico->value)->all(),
                );

                $this->line("✔ {$empresa->nome_comercial} (#{$empresa->id})");
            } catch (Throwable $e) {
                $falhas++;
                $this->error("✘ {$empresa->nome_comercial} (#{$empresa->id}): {$e->getMessage()}");
            }
        }

        $this->info($empresas->count().' empresa(s) processadas, '.$falhas.' falha(s).');

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
