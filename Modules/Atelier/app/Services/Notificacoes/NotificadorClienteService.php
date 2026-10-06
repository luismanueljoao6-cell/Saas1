<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Faturacao\Models\Cliente;

/**
 * Ponto único de disparo de notificações ao cliente (requisito C). Percorre
 * os canais ativos em config('atelier.canais_notificacao') — por omissão só
 * 'mail', que funciona de imediato; 'whatsapp'/'sms' só fazem algo quando
 * as credenciais em .env estiverem preenchidas (ver WhatsAppCanal/SmsCanal).
 * Falha num canal nunca impede os restantes — cada envio é isolado e só
 * regista um warning.
 */
class NotificadorClienteService
{
    public function notificar(
        Cliente $cliente,
        string $assunto,
        string $mensagem,
        ?string $linkAcao = null,
        ?string $textoAcao = null,
    ): void {
        foreach ((array) config('atelier.canais_notificacao') as $canal) {
            $canal = trim($canal);

            match ($canal) {
                'mail' => $this->enviarMail($cliente, $assunto, $mensagem, $linkAcao, $textoAcao),
                'whatsapp' => $this->enviarViaCanal(app(WhatsAppCanal::class), $cliente, $mensagem),
                'sms' => $this->enviarViaCanal(app(SmsCanal::class), $cliente, $mensagem),
                '' => null,
                default => Log::warning("Atelier: canal de notificação desconhecido \"{$canal}\"."),
            };
        }
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

        Notification::route('mail', $cliente->email)
            ->notify(new ClienteAtelierNotification($assunto, $mensagem));
    }

    protected function enviarOuFalhar(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): void
    {
        if (! $cliente->telefone) {
            throw NotificacaoException::contactoEmFalta($canal->identificador());
        }

        $canal->enviar($cliente->telefone, $mensagem);
    }

    protected function enviarMail(Cliente $cliente, string $assunto, string $mensagem, ?string $linkAcao, ?string $textoAcao): void
    {
        if (! $cliente->email) {
            return;
        }

        Notification::route('mail', $cliente->email)
            ->notify(new ClienteAtelierNotification($assunto, $mensagem, $linkAcao, $textoAcao));
    }

    protected function enviarViaCanal(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): void
    {
        if (! $cliente->telefone) {
            return;
        }

        try {
            $canal->enviar($cliente->telefone, $mensagem);
        } catch (NotificacaoException $e) {
            Log::warning('Atelier: falha ao notificar cliente', [
                'canal' => $canal->identificador(),
                'cliente_id' => $cliente->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
