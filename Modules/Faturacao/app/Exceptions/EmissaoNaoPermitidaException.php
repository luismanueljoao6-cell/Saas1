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

    public static function comunicacaoAgtEmFalta(int $dias): self
    {
        return new self(
            "Há documentos por comunicar à AGT há mais de {$dias} dias: a emissão de novos documentos fica bloqueada "
            .'(art. 17.º n.º 5 do Regime Jurídico das Facturas) até a comunicação ser regularizada.'
        );
    }
}
