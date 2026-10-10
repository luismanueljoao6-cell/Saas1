<?php

namespace Modules\Faturacao\Exceptions;

use RuntimeException;

class CadeiaFiscalInterrompidaException extends RuntimeException
{
    public static function documentoAnteriorEmFalta(string $modelo, int $serieId, int $numeroAnterior): self
    {
        return new self(
            "Cadeia fiscal interrompida: o documento n.º {$numeroAnterior} da série {$serieId} ({$modelo}) ".
            'não existe ou não tem hash. A emissão foi cancelada para não gravar um hash_anterior vazio.'
        );
    }
}
