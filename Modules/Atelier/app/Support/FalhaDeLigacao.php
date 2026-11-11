<?php

namespace Modules\Atelier\Support;

use Modules\Core\Notifications\Support\FalhaDeLigacao as ClassificacaoDoCore;
use Throwable;

/** Mantida por compatibilidade: a regra vive no Core, partilhada com o Estúdio. */
final class FalhaDeLigacao
{
    public static function foiAntesDoEnvio(Throwable $erro): bool
    {
        return ClassificacaoDoCore::foiAntesDoEnvio($erro);
    }
}
