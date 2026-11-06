<?php

namespace Modules\Atelier\Console;

use Illuminate\Console\Command;
use Modules\Atelier\Services\CampanhaReconciliador;

class ReconciliarCampanhasCommand extends Command
{
    protected $signature = 'atelier:campanhas-reconciliar {--limite=200 : Máximo de campanhas por execução}';

    protected $description = 'Reconcilia campanhas paradas em processamento com o estado real da Outbox (rede de segurança dos eventos perdidos).';

    public function handle(CampanhaReconciliador $reconciliador): int
    {
        $limite = max(1, min(1000, (int) $this->option('limite')));

        $fechadas = $reconciliador->reconciliarEmProcessamento($limite);

        $this->info("Campanhas concluídas pela reconciliação: {$fechadas}");

        return self::SUCCESS;
    }
}
