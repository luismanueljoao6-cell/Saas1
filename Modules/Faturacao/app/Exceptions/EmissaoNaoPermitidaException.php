<?php

namespace Modules\Faturacao\Exceptions;

use DomainException;

class EmissaoNaoPermitidaException extends DomainException
{
    public static function subscricaoInativa(): self
    {
        return new self('A subscrição da empresa não permite emitir documentos fiscais neste momento.');
    }
}
