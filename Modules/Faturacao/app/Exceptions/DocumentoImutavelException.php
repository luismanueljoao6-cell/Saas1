<?php

namespace Modules\Faturacao\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;

class DocumentoImutavelException extends Exception
{
    public static function paraTentativaDeAlteracao(Model $documento): self
    {
        return new self(sprintf(
            'O documento %s (%s #%d) já foi emitido e não pode ser alterado. Usa uma Nota de Crédito/Débito para o corrigir.',
            $documento->numero_documento ?? '(sem número)',
            class_basename($documento),
            $documento->id,
        ));
    }

    public static function paraTentativaDeExclusao(Model $documento): self
    {
        return new self(sprintf(
            'O documento %s (%s #%d) já foi emitido e não pode ser apagado — nunca, por ninguém, mesmo com privilégios de administrador.',
            $documento->numero_documento ?? '(sem número)',
            class_basename($documento),
            $documento->id,
        ));
    }
}
