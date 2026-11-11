<?php

namespace Modules\Atelier\Support;

/**
 * Porta de segurança para a transferência internacional de dados pessoais
 * (Lei n.º 22/11): WhatsApp (Meta) e SMS levam o contacto do cliente para
 * fora de Angola. Sem o número da autorização da APD (ATELIER_APD_AUTORIZACAO)
 * esses canais não enviam, a menos que a exigência seja desligada de
 * propósito (ATELIER_EXIGIR_AUTORIZACAO_APD=false). Espelha o Estúdio.
 */
final class TransferenciaInternacional
{
    public static function permitida(): bool
    {
        if (! config('atelier.exigir_autorizacao_apd', true)) {
            return true;
        }

        return filled(config('atelier.apd_autorizacao_transferencia'));
    }
}
