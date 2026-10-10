<?php

namespace Modules\Faturacao\Support;

use DomainException;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;

/**
 * Regra de negócio única: só se emitem faturas e recibos com subscrição
 * ativa. Vive aqui (e não nos controllers) para nenhum chamador a contornar.
 */
final class GuardaEmissao
{
    public static function garantir(int $empresaId): void
    {
        $empresa = Empresa::query()->find($empresaId);

        if (! $empresa) {
            throw new DomainException('Empresa inexistente.');
        }

        if (! $empresa->podeEmitirFaturas()) {
            throw EmissaoNaoPermitidaException::subscricaoInativa();
        }
    }
}
