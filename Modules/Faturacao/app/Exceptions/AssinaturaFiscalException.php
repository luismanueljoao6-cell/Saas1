<?php

namespace Modules\Faturacao\Exceptions;

use Exception;

class AssinaturaFiscalException extends Exception
{
    public static function chavePrivadaNaoEncontrada(string $caminho): self
    {
        return new self(
            "Chave privada de assinatura fiscal não encontrada em '{$caminho}'. ".
            'Gera o par de chaves (ver README do módulo Faturação) antes de emitir documentos.'
        );
    }

    public static function chavePrivadaInvalida(): self
    {
        return new self('A chave privada configurada não é uma chave RSA válida — confirma o ficheiro .pem.');
    }

    public static function falhaAoAssinar(string $motivo): self
    {
        return new self("Falha ao gerar a assinatura RSA do documento: {$motivo}");
    }
}
