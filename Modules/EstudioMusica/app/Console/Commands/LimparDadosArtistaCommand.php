<?php

namespace Modules\EstudioMusica\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Services\DadosPessoaisService;
use Modules\Faturacao\Models\Cliente;

/**
 * Direito de eliminação (Lei n.º 22/11) dos dados do Estúdio. NÃO apaga o
 * cliente nem documentos fiscais (conservação obrigatória por lei) nem os
 * ficheiros de áudio dos projetos — ver Services\DadosPessoaisService.
 *
 *   php artisan estudiomusica:limpar-dados-artista 1 42
 */
class LimparDadosArtistaCommand extends Command
{
    protected $signature = 'estudiomusica:limpar-dados-artista
        {empresa : ID da empresa}
        {cliente : ID do cliente (artista)}
        {--force : Não pedir confirmação}';

    protected $description = 'Apaga a ficha do artista, consentimentos, cupões, registo de campanhas e pedidos de orçamento de um cliente.';

    public function handle(TenantManager $tenantManager, DadosPessoaisService $dados): int
    {
        $empresa = Empresa::findOrFail((int) $this->argument('empresa'));
        $tenantManager->set($empresa->id);

        $cliente = Cliente::withTrashed()->findOrFail((int) $this->argument('cliente'));

        if (! $this->option('force') && ! $this->confirm("Limpar os dados do Estúdio de \"{$cliente->nome}\"? Não pode ser desfeito.")) {
            $this->warn('Cancelado.');

            return self::FAILURE;
        }

        $r = $dados->limpar($cliente);

        $this->info("Ficha limpa. Removidos: {$r['pedidos_orcamento']} pedido(s) de orçamento, {$r['reclamacoes']} reclamação(ões), {$r['mensagens']} registo(s) de campanhas, {$r['cupons']} cupão(ões).");
        $this->line('Mantidos por obrigação legal: cliente e documentos fiscais. Os ficheiros de áudio dos projetos não foram apagados.');

        return self::SUCCESS;
    }
}
