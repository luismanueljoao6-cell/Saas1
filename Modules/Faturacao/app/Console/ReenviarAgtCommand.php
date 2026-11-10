<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Services\AgtSubmissaoService;

/**
 * Saída operacional das submissões AGT paradas sem requestID (erro técnico ou
 * PAYLOAD_DIVERGENTE). Sem argumento lista-as; com o ID de uma submissão repõe-a
 * em "pendente" e o próximo faturacao:agt-sincronizar envia-a.
 */
class ReenviarAgtCommand extends Command
{
    protected $signature = 'faturacao:agt-reenviar
        {submissao? : ID da submissão (tabela agt_submissoes); sem ID, lista as que precisam de intervenção}
        {--empresa= : Ao listar, limita a uma empresa (ID)}
        {--force : Não pede confirmação (PAYLOAD_DIVERGENTE)}';

    protected $description = 'Lista e reenvia à AGT as submissões paradas sem requestID (erro técnico ou PAYLOAD_DIVERGENTE)';

    public function handle(AgtSubmissaoService $servico): int
    {
        if ($this->argument('submissao') === null) {
            return $this->listar($servico);
        }

        $submissao = AgtSubmissao::query()->find((int) $this->argument('submissao'));

        if (! $submissao) {
            $this->error('Submissão não encontrada.');

            return self::FAILURE;
        }

        foreach ((array) $submissao->erros as $erro) {
            $this->line('AGT: '.$this->textoDoErro($erro));
        }

        if (($submissao->erros[0]['codigo'] ?? null) === 'PAYLOAD_DIVERGENTE' && ! $this->option('force')) {
            $this->warn("O pedido original (UUID {$submissao->submission_uuid}) pode já ter sido aceite pela AGT: o reenvio segue com um UUID novo.");

            if (! $this->confirm("Confirmas que {$submissao->documento_no} não consta no portal da AGT?", false)) {
                $this->line('Nada foi alterado.');

                return self::FAILURE;
            }
        }

        try {
            $nova = $servico->reenviar($submissao);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Submissão #{$nova->id} ({$nova->documento_no}) reposta em «pendente»; o próximo faturacao:agt-sincronizar envia-a.");

        return self::SUCCESS;
    }

    protected function listar(AgtSubmissaoService $servico): int
    {
        $empresa = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;
        $paradas = $servico->aguardamIntervencao($empresa);

        if ($paradas->isEmpty()) {
            $this->info('Nenhuma submissão sem requestID precisa de intervenção.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Empresa', 'Documento', 'Estado', 'Erro', 'Tentativas'],
            $paradas->map(fn (AgtSubmissao $s) => [
                $s->id,
                $s->empresa_id,
                $s->documento_no,
                $s->rotulo(),
                (string) ($s->erros[0]['codigo'] ?? ''),
                $s->tentativas,
            ])->all(),
        );

        $this->line('Reenvia uma com: php artisan faturacao:agt-reenviar <ID>');

        return self::SUCCESS;
    }

    protected function textoDoErro(mixed $erro): string
    {
        return is_array($erro) ? trim(($erro['codigo'] ?? '').' '.($erro['descricao'] ?? '')) : (string) $erro;
    }
}
