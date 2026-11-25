<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\NumeroTelefone;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\NotificationDispatcher;
use Modules\Faturacao\Models\Cliente;
use Throwable;

/**
 * Ponto único de disparo de notificações ao cliente (requisito C).
 *
 * notificar() devolve true quando pelo menos um canal aceitou ou colocou a
 * mensagem na fila. Não significa entrega final: os canais assíncronos têm
 * o seu próprio ciclo de tentativas e confirmação.
 */
class NotificadorClienteService
{
    public function notificar(
        Cliente $cliente,
        string $assunto,
        string $mensagem,
        ?string $linkAcao = null,
        ?string $textoAcao = null,
    ): bool {
        $algumCanalAceitou = false;

        foreach ((array) config('atelier.canais_notificacao') as $canal) {
            $canal = trim($canal);

            if ($canal === '') {
                continue;
            }

            if (! in_array($canal, ['mail', 'whatsapp', 'sms'], true)) {
                Log::warning("Atelier: canal de notificação desconhecido \"{$canal}\".");

                continue;
            }

            try {
                $aceitou = match ($canal) {
                    'mail' => $this->enviarMail($cliente, $assunto, $mensagem, $linkAcao, $textoAcao),
                    'whatsapp' => $this->enviarViaCanal(app(WhatsAppCanal::class), $cliente, $mensagem),
                    'sms' => $this->enviarViaCanal(app(SmsCanal::class), $cliente, $mensagem),
                };

                $algumCanalAceitou = $aceitou || $algumCanalAceitou;
            } catch (Throwable $e) {
                Log::warning('Atelier: falha ao notificar cliente por um canal', [
                    'canal' => $canal,
                    'cliente_id' => $cliente->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return $algumCanalAceitou;
    }

    /**
     * Coloca uma mensagem de telefone na Outbox partilhada do Core.
     *
     * Este método é opt-in: os fluxos legados e as campanhas não mudam de
     * semântica até passarem a acompanhar o estado assíncrono da notificação.
     */
    public function enfileirarViaOutbox(
        Cliente $cliente,
        string $canal,
        string $mensagem,
        string $chaveIdempotencia,
        array $contexto = [],
    ): NotificationOutbox {
        if (! in_array($canal, ['whatsapp', 'sms'], true)) {
            throw NotificacaoException::naoConfigurado($canal);
        }

        $destino = NumeroTelefone::internacional($cliente->telefone);
        if ($destino === null) {
            throw NotificacaoException::contactoEmFalta($canal);
        }

        if (! $cliente->empresa_id) {
            throw new \RuntimeException('O cliente não está associado a uma empresa.');
        }

        return app(NotificationDispatcher::class)->dispatch(
            channel: 'atelier.'.$canal,
            recipient: $destino,
            payload: array_merge($contexto, ['message' => $mensagem]),
            idempotencyKey: $chaveIdempotencia,
            empresaId: (int) $cliente->empresa_id,
        );
    }

    /**
     * Variante para as Campanhas (requisito D): ao contrário de notificar(),
     * respeita SÓ o canal escolhido pelo administrador ao criar a campanha
     * (nunca dispara nos outros canais configurados) e NÃO engole a exceção
     * — DispararCampanhaJob precisa de saber se falhou para marcar o envio
     * como 'falhou' em atelier_campanha_envios, em vez de 'enviado'.
     *
     * @throws NotificacaoException
     */
    public function notificarViaCanalUnico(Cliente $cliente, string $canal, string $assunto, string $mensagem): void
    {
        match ($canal) {
            'mail' => $this->enviarMailOuFalhar($cliente, $assunto, $mensagem),
            'whatsapp' => $this->enviarOuFalhar(app(WhatsAppCanal::class), $cliente, $mensagem),
            'sms' => $this->enviarOuFalhar(app(SmsCanal::class), $cliente, $mensagem),
            default => throw NotificacaoException::naoConfigurado($canal),
        };
    }

    protected function enviarMailOuFalhar(Cliente $cliente, string $assunto, string $mensagem): void
    {
        if (! $cliente->email) {
            throw NotificacaoException::contactoEmFalta('mail');
        }

        Notification::sendNow(
            Notification::route('mail', $cliente->email),
            new ClienteAtelierNotification($assunto, $mensagem),
        );
    }

    protected function enviarOuFalhar(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): void
    {
        $destino = NumeroTelefone::internacional($cliente->telefone);

        if ($destino === null) {
            throw NotificacaoException::contactoEmFalta($canal->identificador());
        }

        $canal->enviar($destino, $mensagem);
    }

    protected function enviarMail(Cliente $cliente, string $assunto, string $mensagem, ?string $linkAcao, ?string $textoAcao): bool
    {
        if (! $cliente->email) {
            return false;
        }

        Notification::route('mail', $cliente->email)
            ->notify(new ClienteAtelierNotification($assunto, $mensagem, $linkAcao, $textoAcao));

        return true;
    }

    protected function enviarViaCanal(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): bool
    {
        $destino = NumeroTelefone::internacional($cliente->telefone);

        if ($destino === null) {
            return false;
        }

        try {
            $canal->enviar($destino, $mensagem);

            return true;
        } catch (NotificacaoException $e) {
            Log::warning('Atelier: falha ao notificar cliente', [
                'canal' => $canal->identificador(),
                'cliente_id' => $cliente->id,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
