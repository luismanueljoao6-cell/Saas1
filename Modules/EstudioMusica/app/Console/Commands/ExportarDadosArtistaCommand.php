<?php

namespace Modules\EstudioMusica\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Services\DadosPessoaisService;
use Modules\Faturacao\Models\Cliente;

/**
 * Direito de acesso (Lei n.º 22/11): exporta em JSON o que o Estúdio
 * guarda sobre um artista.
 *
 *   php artisan estudiomusica:exportar-dados-artista 1 42 --saida=/tmp/artista-42.json
 */
class ExportarDadosArtistaCommand extends Command
{
    protected $signature = 'estudiomusica:exportar-dados-artista
        {empresa : ID da empresa}
        {cliente : ID do cliente (artista)}
        {--saida= : Caminho do ficheiro JSON (por omissão escreve no ecrã)}';

    protected $description = 'Exporta (JSON) os dados pessoais que o Estúdio guarda sobre um artista.';

    public function handle(TenantManager $tenantManager, DadosPessoaisService $dados): int
    {
        $empresa = Empresa::findOrFail((int) $this->argument('empresa'));
        $tenantManager->set($empresa->id);

        $cliente = Cliente::withTrashed()->findOrFail((int) $this->argument('cliente'));

        $json = json_encode($dados->exportar($cliente), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($saida = $this->option('saida')) {
            file_put_contents($saida, $json);
            $this->info("Exportação gravada em {$saida}.");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
