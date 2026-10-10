<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Modules\Subscricoes\Models\Plano;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('planos:preco {slug : Slug do plano} {preco : Novo preço em Kz, ex.: 6500}', function (string $slug, string $preco) {
    if (! is_numeric($preco) || (float) $preco <= 0) {
        $this->error('O preço tem de ser um número positivo.');

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
