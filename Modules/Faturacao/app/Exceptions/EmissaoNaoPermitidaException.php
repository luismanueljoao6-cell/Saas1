<?php

namespace Modules\Faturacao\Exceptions;

use DomainException;

class EmissaoNaoPermitidaException extends DomainException
{
    public static function subscricaoInativa(): self
    {
        return new self('A subscrição da empresa não permite emitir documentos fiscais neste momento.');
    }

    public static function limiteMensalAtingido(int $limite): self
    {
        return new self(
            "O plano atual permite no máximo {$limite} fatura(s) emitida(s) por mês. "
            .'Passa para um plano superior ou aguarda o próximo mês.'
        );
    }
}
