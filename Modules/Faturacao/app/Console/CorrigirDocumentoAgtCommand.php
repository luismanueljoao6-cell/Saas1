<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Services\AgtCorrecaoService;

/**
 * Cria o rascunho da fatura que corrige um documento inválido ou rejeitado pela AGT
 * (documento novo, documentStatus "C" + rejectedDocumentNo). Não emite nem envia nada.
 */
class CorrigirDocumentoAgtCommand extends Command
{
    protected $signature = 'faturacao:agt-corrigir {submissao : ID da submissão (tabela agt_submissoes) inválida ou rejeitada}';

    protected $description = 'Cria o rascunho da fatura que corrige um documento inválido ou rejeitado pela AGT (documentStatus C)';

    public function handle(AgtCorrecaoService $correcoes): int
    {
        $submissao = AgtSubmissao::query()->find((int) $this->argument('submissao'));

        if (! $submissao) {
            $this->error('Submissão não encontrada.');

            return self::FAILURE;
        }

        foreach ((array) $submissao->erros as $erro) {
            $this->line('AGT: '.(is_array($erro) ? trim(($erro['codigo'] ?? '').' '.($erro['descricao'] ?? '')) : (string) $erro));
        }

        try {
            $rascunho = $correcoes->criarRascunhoDeCorrecao($submissao);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Rascunho #{$rascunho->id} para corrigir {$submissao->documento_no}. Revê-o em /faturacao/faturas/{$rascunho->id} e emite-o: segue para a AGT com documentStatus C.");

        return self::SUCCESS;
    }
}
