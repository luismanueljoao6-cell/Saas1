<?php

namespace Modules\Subscricoes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Subscricoes\Models\Plano;

/**
 * Preços e limites são EXEMPLOS para desenvolvimento/demonstração — ajusta
 * antes de ires para produção. Corre com:
 *   php artisan db:seed --class="Modules\Subscricoes\Database\Seeders\PlanosSeeder"
 */
class PlanosSeeder extends Seeder
{
    public function run(): void
    {
        $planos = [
            [
                'nome' => 'Básico',
                'slug' => 'basico',
                'descricao' => 'Para começar: faturação simples e um utilizador.',
                'preco' => 9900,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => 1, 'max_faturas_mes' => 50],
                'ordem' => 1,
            ],
            [
                'nome' => 'Profissional',
                'slug' => 'profissional',
                'descricao' => 'Para equipas pequenas com vários módulos ativos.',
                'preco' => 24900,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => 5, 'max_faturas_mes' => 500],
                'ordem' => 2,
            ],
            [
                'nome' => 'Empresarial',
                'slug' => 'empresarial',
                'descricao' => 'Utilizadores e faturas ilimitados, suporte prioritário.',
                'preco' => 59900,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => null, 'max_faturas_mes' => null],
                'ordem' => 3,
            ],
        ];

        foreach ($planos as $plano) {
            Plano::updateOrCreate(['slug' => $plano['slug']], $plano);
        }

        $this->command?->info('Planos de exemplo criados/atualizados: '.implode(', ', array_column($planos, 'nome')));
    }
}
