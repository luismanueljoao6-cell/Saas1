<?php

namespace Modules\Faturacao\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class AssinaturaFiscalException extends Exception
{
    public static function chavePrivadaNaoEncontrada(string $caminho): self
    {
        // O caminho vai só para o log — nunca para a mensagem (que pode chegar ao utilizador).
        Log::critical('Chave privada de assinatura fiscal não encontrada.', ['caminho' => $caminho]);

        return new self('Chave privada de assinatura fiscal não encontrada (ver README do módulo Faturação).');
    }

    public static function chavePrivadaInvalida(): self
    {
        return new self('A chave privada configurada não é uma chave RSA válida.');
    }

    public static function chavePublicaNaoEncontrada(): self
    {
        return new self('Chave pública de verificação não encontrada.');
    }

    public static function chavePublicaInvalida(): self
    {
        return new self('A chave pública fornecida não é uma chave RSA válida.');
    }

    public static function falhaAoAssinar(string $motivo): self
    {
        Log::critical('Falha OpenSSL ao assinar documento.', ['motivo' => $motivo]);

        return new self('Falha ao gerar a assinatura RSA do documento.');
    }
}
