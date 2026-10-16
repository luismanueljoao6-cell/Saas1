<?php

namespace Modules\EstudioMusica\Support;

use Modules\EstudioMusica\Notifications\Channels\LogChannel;

/**
 * Porta de segurança para a transferência internacional de dados
 * pessoais (Lei n.º 22/11): WhatsApp (Meta) e SMS (Twilio) levam o
 * contacto do cliente para fora de Angola. Enquanto não houver o número
 * da autorização da APD (ESTUDIOMUSICA_APD_AUTORIZACAO) esses canais não
 * enviam — a menos que o responsável desligue a exigência de propósito
 * (ESTUDIOMUSICA_EXIGIR_AUTORIZACAO_APD=false). O canal `log` nunca sai
 * do servidor, por isso nunca é bloqueado.
 */
final class TransferenciaInternacional
{
    public static function permitida(object $canal): bool
    {
        if ($canal instanceof LogChannel) {
            return true;
        }

        if (! config('estudiomusica.exigir_autorizacao_apd', true)) {
            return true;
        }

        return filled(config('estudiomusica.apd_autorizacao_transferencia'));
    }
}
