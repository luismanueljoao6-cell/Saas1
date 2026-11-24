<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantProvisionerRegistry;
use Modules\Core\Support\Servico;
use Throwable;

class ProvisionarServicosCommand extends Command
{
    protected $signature = 'core:provisionar-servicos {--empresa= : ID da empresa (por omissão, todas)}';

    protected $description = 'Cria ou repara papéis e permissões dos serviços ativos de cada empresa (idempotente; tudo ou nada por empresa)';

    public function handle(TenantProvisionerRegistry $registry): int
    {
        $empresas = Empresa::query()
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        $falhas = 0;

        foreach ($empresas as $empresa) {
            try {
                // Tudo ou nada por empresa: se o módulo B falha, o que o módulo A
                // já criou para esta empresa é desfeito (sem provisionamento parcial).
                DB::transaction(fn () => $registry->provision(
                    $empresa->id,
                    $empresa->servicosAderidos()->map(fn (Servico $servico) => $servico->value)->all(),
                ));

                $this->line("✔ {$empresa->nome_comercial} (#{$empresa->id})");
            } catch (Throwable $e) {
                $falhas++;

                // O registry embrulha a falha do módulo; a causa real vem em getPrevious().
                $causa = $e->getPrevious()?->getMessage();

                $this->error("✘ {$empresa->nome_comercial} (#{$empresa->id}): {$e->getMessage()}".($causa ? " — causa: {$causa}" : ''));

                Log::error('Falha ao provisionar os serviços da empresa', [
                    'empresa_id' => $empresa->id,
                    'erro' => $e->getMessage(),
                    'causa' => $causa,
                ]);
            }
        }

        $this->info($empresas->count().' empresa(s) processadas, '.$falhas.' falha(s).');

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
