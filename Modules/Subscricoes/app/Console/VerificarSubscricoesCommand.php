<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Modules\Subscricoes\Jobs\VerificarSubscricoesExpiradasJob;

class VerificarSubscricoesCommand extends Command
{
    protected $signature = 'subscricoes:verificar';

    protected $description = 'Corre agora a rotina diária de subscrições (expirações, trials, renovações)';

    public function handle(): int
    {
        VerificarSubscricoesExpiradasJob::dispatchSync();

        $this->info('Rotina de subscrições concluída (ver storage/logs/laravel.log para o detalhe).');

        return self::SUCCESS;
    }
}
