<?php

namespace Modules\Faturacao\Traits;

use Modules\Faturacao\Exceptions\DocumentoImutavelException;

/**
 * "A impossibilidade de apagar ou alterar faturas emitidas e validadas" era
 * um requisito explícito do pedido original — esta trait é a aplicação
 * literal disso, ao nível do Eloquent, para Fatura, NotaCreditoDebito e
 * Recibo (qualquer model que a use).
 *
 * Verifica sempre o estado ORIGINAL (antes desta operação), nunca o novo:
 * a própria transição rascunho -> emitida tem de ser permitida (é assim
 * que um documento chega a ser emitido); o que fica bloqueado é qualquer
 * alteração DEPOIS de já estar emitida — incluindo por um super admin.
 *
 * Nota: isto protege ao nível da aplicação. Para uma segunda camada de
 * defesa (ver README), considera também revogar o privilégio UPDATE/DELETE
 * do utilizador de base de dados da aplicação nestas tabelas, ou um
 * trigger de base de dados — a trait não substitui isso, complementa.
 */
trait Imutavel
{
    protected static array $estadosFinaisImutaveis = ['emitida', 'emitido'];

    public static function bootImutavel(): void
    {
        static::updating(function ($model): void {
            if (static::estadoOriginalEhFinal($model)) {
                throw DocumentoImutavelException::paraTentativaDeAlteracao($model);
            }
        });

        static::deleting(function ($model): void {
            if (static::estadoOriginalEhFinal($model)) {
                throw DocumentoImutavelException::paraTentativaDeExclusao($model);
            }
        });
    }

    protected static function estadoOriginalEhFinal($model): bool
    {
        return in_array($model->getOriginal('estado'), static::$estadosFinaisImutaveis, true);
    }
}
