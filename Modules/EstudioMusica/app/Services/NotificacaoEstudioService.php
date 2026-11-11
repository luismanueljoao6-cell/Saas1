<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Notifications\NotificationDispatcher;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Support\NumeroTelefone;
use Modules\EstudioMusica\Support\TransferenciaInternacional;
use Modules\Faturacao\Models\Cliente;

/**
 * Compõe notificações do Estúdio e enfileira o envio fora do pedido HTTP.
 * O dispatch confirma apenas que o trabalho foi enfileirado; não prova
 * que o fornecedor entregou a mensagem ao destinatário.
 */
class NotificacaoEstudioService
{
    /**
     * Verifica se existe pelo menos um canal que o sistema pode usar.
     * E-mail não depende da autorização de transferência internacional;
     * telefone só é elegível quando WhatsApp ou SMS estão autorizados.
     */
    public function podeEnviar(Cliente $cliente): bool
    {
        if ($cliente->trashed()) {
            return false;
        }

        if (trim((string) $cliente->email) !== '') {
            return true;
        }

        if (trim((string) $cliente->telefone) === '') {
            return false;
        }

        return TransferenciaInternacional::permitida(app(CanalWhatsAppInterface::class))
            || TransferenciaInternacional::permitida(app(CanalSmsInterface::class));
    }

    /**
     * @return bool true quando o dispatch foi aceite; false quando não há
     *              destinatário ou canal legal/técnico elegível.
     */
    public function enviar(Cliente $cliente, string $assunto, string $mensagem): bool
    {
        if (! $this->podeEnviar($cliente)) {
            Log::notice('EstudioMusica: notificação não enfileirada — cliente sem canal elegível.', [
                'cliente_id' => $cliente->id,
                'empresa_id' => $cliente->empresa_id,
                'tem_telefone' => trim((string) $cliente->telefone) !== '',
                'tem_email' => trim((string) $cliente->email) !== '',
            ]);

            return false;
        }

        $telefone = trim((string) $cliente->telefone);
        $email = trim((string) $cliente->email);

        EnviarMensagemClienteJob::dispatch(
            $telefone !== '' ? $telefone : null,
            $email !== '' ? $email : null,
            $assunto,
            $mensagem
        );

        return true;
    }

    /**
     * Lembrete de sessão IDEMPOTENTE: repetir o job (ou perder a marcação da
     * coluna lembrete_*_enviado_em) nunca duplica a mensagem.
     *
     *  - Telefone: vai pela Outbox do Core com uma chave determinística por
     *    sessão, janela, destinatário e conteúdo. Uma segunda chamada recupera
     *    a mesma notificação em vez de criar outra.
     *  - E-mail: também vai pela Outbox (canal estudio.email), com a mesma chave
     *    determinística. Já não depende da cache nem do job legado.
     *
     * Já não há fallback WhatsApp -> SMS neste fluxo: um timeout no WhatsApp
     * mandava também SMS e duplicava a mensagem.
     *
     * @return bool true quando pelo menos um destino ficou (ou já estava) enfileirado
     */
    public function lembreteSessao(SessaoEstudio $sessao, int $horasAntes): bool
    {
        $cliente = $sessao->cliente;

        if ($cliente->trashed()) {
            return false;
        }

        $data = $sessao->inicio_previsto->format('d/m').' às '.$sessao->inicio_previsto->format('H:i');
        $assunto = 'Lembrete de sessão de estúdio';
        $mensagem = "Olá! A tua sessão de {$sessao->rotuloTipoServico()} está marcada para {$data}, daqui a cerca de {$horasAntes}h. Até já!";

        $enfileirou = false;

        $email = trim((string) $cliente->email);
        if ($email !== '') {
            app(NotificationDispatcher::class)->dispatch(
                channel: 'estudio.email',
                recipient: $email,
                payload: ['subject' => $assunto, 'message' => $mensagem],
                idempotencyKey: $this->chaveLembrete($sessao, $horasAntes, $email, $mensagem),
                empresaId: (int) $sessao->empresa_id,
            );
            $enfileirou = true;
        }

        $destino = NumeroTelefone::internacional($cliente->telefone);
        if ($destino !== null) {
            $canal = $this->canalTelefoneAutorizado();

            if ($canal !== null) {
                app(NotificationDispatcher::class)->dispatch(
                    channel: $canal,
                    recipient: $destino,
                    payload: ['message' => $mensagem],
                    idempotencyKey: $this->chaveLembrete($sessao, $horasAntes, $destino, $mensagem),
                    empresaId: (int) $sessao->empresa_id,
                );
                $enfileirou = true;
            } else {
                Log::notice('EstudioMusica: lembrete por telefone não enfileirado — falta a autorização da APD.', [
                    'sessao_id' => $sessao->id,
                    'empresa_id' => $sessao->empresa_id,
                ]);
            }
        }

        if (! $enfileirou) {
            Log::notice('EstudioMusica: lembrete não enfileirado — cliente sem canal elegível.', [
                'sessao_id' => $sessao->id,
                'empresa_id' => $sessao->empresa_id,
            ]);
        }

        return $enfileirou;
    }

    /** O canal de telefone que a política APD permite, ou null se nenhum. */
    protected function canalTelefoneAutorizado(): ?string
    {
        if (TransferenciaInternacional::permitida(app(CanalWhatsAppInterface::class))) {
            return 'estudio.whatsapp';
        }

        if (TransferenciaInternacional::permitida(app(CanalSmsInterface::class))) {
            return 'estudio.sms';
        }

        return null;
    }

    /** Determinística: mesma sessão + janela + destinatário + conteúdo = mesma chave. */
    protected function chaveLembrete(SessaoEstudio $sessao, int $horasAntes, string $destinatario, string $mensagem): string
    {
        return 'estudio-lembrete-sessao-'.$sessao->id.'-'.$horasAntes.'h-'.substr(sha1($destinatario."\n".$mensagem), 0, 16);
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

    /**
     * Avisa a EQUIPA (produtor/engenheiro do projeto) quando o cliente
     * aprova ou pede ajustes no portal — antes, a decisão ficava invisível
     * até alguém abrir o projeto. Só e-mail (os utilizadores não têm
     * telefone registado).
     */
    public function equipaDecisaoCliente(VersaoAudio $versao, string $decisao): void
    {
        $faixa = $versao->faixaMusical;
        $projeto = $faixa->projetoMusical;
        $cliente = $projeto->cliente;
        $nome = $cliente->nome_artistico ?: $cliente->nome;
        $acao = $decisao === 'aprovada' ? 'aprovou' : 'pediu ajustes à';

        collect([$projeto->produtor, $projeto->engenheiro])
            ->filter()
            ->pluck('email')
            ->filter()
            ->unique()
            ->each(fn (string $email) => EnviarMensagemClienteJob::dispatch(
                null,
                $email,
                'Decisão do cliente no portal',
                "{$nome} {$acao} versão \"{$versao->rotulo}\" da faixa \"{$faixa->nome}\" (projeto \"{$projeto->nome}\")."
            ));
    }

    /** Avisa o estúdio (e-mail da empresa) e confirma a receção a quem reclamou. */
    public function reclamacaoRecebida(Empresa $empresa, Reclamacao $reclamacao): void
    {
        if ($empresa->email) {
            EnviarMensagemClienteJob::dispatch(
                null,
                $empresa->email,
                'Nova reclamação recebida',
                "Reclamação #{$reclamacao->id} de {$reclamacao->nome}: {$reclamacao->assunto}. Consulta e responde em Estúdio > Reclamações."
            );
        }

        EnviarMensagemClienteJob::dispatch(
            $reclamacao->telefone,
            $reclamacao->email,
            'Recebemos a tua reclamação',
            "Recebemos a tua reclamação (ref. #{$reclamacao->id}) sobre \"{$reclamacao->assunto}\". Vamos responder o mais depressa possível."
        );
    }

    public function reclamacaoRespondida(Reclamacao $reclamacao): void
    {
        EnviarMensagemClienteJob::dispatch(
            $reclamacao->telefone,
            $reclamacao->email,
            'Resposta à tua reclamação',
            "Resposta à tua reclamação #{$reclamacao->id}: {$reclamacao->resposta}"
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
