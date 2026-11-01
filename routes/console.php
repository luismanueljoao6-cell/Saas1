<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Subscricoes\Models\Plano;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('planos:preco {slug : Slug do plano} {preco : Novo preço em Kz, ex.: 6500}', function (string $slug, string $preco) {
    if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $preco) || (float) $preco < 0.01) {
        $this->error('O preço tem de ser positivo, com no máximo 8 inteiros e 2 casas decimais (ex.: 6500 ou 6500.50).');

        return 1;
    }

    $plano = Plano::where('slug', $slug)->first();

    if (! $plano) {
        $this->error("Plano '{$slug}' não encontrado. Existentes: ".Plano::pluck('slug')->implode(', '));

        return 1;
    }

    $antigo = $plano->preco;
    $plano->update(['preco' => $preco]);

    $this->info("Plano {$plano->nome}: {$antigo} → {$plano->fresh()->preco} {$plano->moeda}. Aplica-se às próximas cobranças; pagamentos já gerados mantêm o valor.");

    return 0;
})->purpose('Altera o preço de um plano (só afeta cobranças futuras)');

// Comunicação com a AGT: envia os documentos pendentes e consulta o estado dos enviados.
// Só faz algo com FATURACAO_AGT_ATIVO=true. Requer o agendador (php artisan schedule:work / cron).
Schedule::command('faturacao:agt-sincronizar')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('faturacao.agt.ativo'));
