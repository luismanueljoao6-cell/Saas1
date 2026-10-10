<?php

namespace Modules\Subscricoes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Subscricoes\Models\Plano;

/**
 * Pacotes de lançamento. Os preços (Kz/mês, IVA incluído) são hipóteses
 * baseadas numa pesquisa de mercado de outubro de 2026 — para os alterar
 * depois, usa:   php artisan planos:preco <slug> <novo-preço>
 *
 * Repetir este seeder NUNCA repõe preços já alterados (firstOrCreate), e
 * desativa — sem apagar — os planos de exemplo antigos.
 *
 *   php artisan db:seed --class="Modules\Subscricoes\Database\Seeders\PlanosSeeder"
 */
class PlanosSeeder extends Seeder
{
    public function run(): void
    {
        $pacotes = [
            [
                'nome' => 'Faturação',
                'slug' => 'faturacao',
                'descricao' => 'Faturas, recibos, notas de crédito e débito, clientes, produtos e exportação SAF-T.',
                'preco' => 5500,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => 2, 'max_faturas_mes' => null],
                'servicos' => ['faturacao'],
                'ordem' => 1,
            ],
            [
                'nome' => 'Faturação + Atelier',
                'slug' => 'faturacao-atelier',
                'descricao' => 'Tudo da Faturação, mais pedidos, medidas, provas, equipa, portfólio e campanhas do Atelier.',
                'preco' => 11000,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => 5, 'max_faturas_mes' => null],
                'servicos' => ['faturacao', 'atelier'],
                'ordem' => 2,
            ],
            [
                'nome' => 'Faturação + Estúdio',
                'slug' => 'faturacao-estudio',
                'descricao' => 'Tudo da Faturação, mais salas, sessões, projetos musicais e portal do cliente do Estúdio.',
                'preco' => 12000,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => 5, 'max_faturas_mes' => null],
                'servicos' => ['faturacao', 'estudio'],
                'ordem' => 3,
            ],
            [
                'nome' => 'Completo',
                'slug' => 'completo',
                'descricao' => 'Faturação, Atelier e Estúdio, com utilizadores ilimitados.',
                'preco' => 16500,
                'periodo_dias' => 30,
                'limites' => ['max_utilizadores' => null, 'max_faturas_mes' => null],
                'servicos' => ['faturacao', 'atelier', 'estudio'],
                'ordem' => 4,
            ],
        ];

        foreach ($pacotes as $pacote) {
            Plano::firstOrCreate(['slug' => $pacote['slug']], $pacote);
        }

        // Os planos de exemplo antigos deixam de ser oferecidos, mas não se
        // apagam: subscrições e pagamentos já existentes continuam a apontar para eles.
        Plano::whereIn('slug', ['basico', 'profissional', 'empresarial'])->update(['ativo' => false]);

        $this->command?->info('Pacotes disponíveis: '.implode(', ', array_column($pacotes, 'nome')));
    }
}
