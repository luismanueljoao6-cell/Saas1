<?php

namespace Modules\EstudioMusica\Services;

use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\Faturacao\Models\Cliente;

/**
 * "Automação de Notificações" (secção D): compõe o texto dos 5 avisos
 * pedidos — lembrete de sessão, atualização de estado, nova demo
 * disponível, aprovação pendente, projeto finalizado — e entrega-os a
 * Jobs\EnviarMensagemClienteJob, que os envia por WhatsApp, SMS e e-mail
 * fora do pedido HTTP. Nenhum canal tem de estar configurado para os
 * outros funcionarem (ver o job).
 */
class NotificacaoEstudioService
{
    public function enviar(Cliente $cliente, string $assunto, string $mensagem): void
    {
        // Um cliente apagado (soft delete) em Faturação mantém-se nos
        // projetos/sessões antigos, por histórico, mas deixa de receber
        // mensagens.
        if ($cliente->trashed()) {
            return;
        }

        EnviarMensagemClienteJob::dispatch($cliente->telefone, $cliente->email, $assunto, $mensagem);
    }

    public function lembreteSessao(SessaoEstudio $sessao, int $horasAntes): void
    {
        $data = $sessao->inicio_previsto->format('d/m').' às '.$sessao->inicio_previsto->format('H:i');

        $this->enviar(
            $sessao->cliente,
            'Lembrete de sessão de estúdio',
            "Olá! A tua sessão de {$sessao->rotuloTipoServico()} está marcada para {$data}, daqui a cerca de {$horasAntes}h. Até já!"
        );
    }

    public function atualizacaoEstadoProjeto(ProjetoMusical $projeto): void
    {
        $this->enviar(
            $projeto->cliente,
            'Atualização do teu projeto',
            "O teu projeto \"{$projeto->nome}\" entrou em fase de {$projeto->rotuloEstado()}!"
        );
    }

    /**
     * É este o exemplo literal da secção D: "A sua música [Nome] entrou em
     * fase de Mixagem!" — o estado avança faixa a faixa, não só por projeto.
     */
    public function atualizacaoEstadoFaixa(FaixaMusical $faixa): void
    {
        $this->enviar(
            $faixa->projetoMusical->cliente,
            'Atualização da tua música',
            "A tua música \"{$faixa->nome}\" entrou em fase de {$faixa->rotuloEstado()}!"
        );
    }

    public function linkPortal(Cliente $cliente, string $url): void
    {
        $this->enviar(
            $cliente,
            'O teu acesso ao Portal do Cliente',
            "Aqui está o teu acesso ao Portal do Cliente, onde podes ouvir as versões, deixar comentários e aprovar: {$url}"
        );
    }

    public function novaDemoDisponivel(VersaoAudio $versao): void
    {
        $faixa = $versao->faixaMusical;

        $this->enviar(
            $faixa->projetoMusical->cliente,
            'Nova versão disponível',
            "A versão \"{$versao->rotulo}\" da faixa \"{$faixa->nome}\" já está no teu Portal do Cliente para audição!"
        );
    }

    public function projetoConcluido(ProjetoMusical $projeto): void
    {
        $this->enviar(
            $projeto->cliente,
            'Projeto finalizado',
            "O teu projeto \"{$projeto->nome}\" foi concluído! Já podes aceder a tudo no teu Portal do Cliente."
        );
    }

    public function aprovacaoPendente(VersaoAudio $versao): void
    {
        $faixa = $versao->faixaMusical;

        $this->enviar(
            $faixa->projetoMusical->cliente,
            'Aprovação pendente',
            "A versão \"{$versao->rotulo}\" da faixa \"{$faixa->nome}\" está à espera da tua aprovação no Portal do Cliente."
        );
    }
}
