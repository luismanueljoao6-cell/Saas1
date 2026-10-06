<?php

namespace Modules\EstudioMusica\Exceptions;

use Modules\EstudioMusica\Models\SalaEstudio;
use RuntimeException;

/**
 * Lançada por Services\AgendamentoService quando uma sessão pedida
 * sobrepõe-se a outra já existente na mesma sala (prevenção de double
 * booking — secção B do requisito).
 */
class ConflitoAgendamentoException extends RuntimeException
{
    public static function paraSala(SalaEstudio $sala, string $inicio, string $fim): self
    {
        return new self(
            "A sala \"{$sala->nome}\" já tem uma sessão marcada entre {$inicio} e {$fim}."
        );
    }
}
