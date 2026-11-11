<?php

namespace Modules\Atelier\Exceptions;

use Exception;

class NotificacaoException extends Exception
{
    public static function falhaNoEnvio(string $canal, string $motivo): self
    {
        return new self("Falha ao enviar notificação por {$canal}: {$motivo}");
    }

    public static function resultadoIndeterminado(string $canal, string $motivo): NotificacaoResultadoIndeterminadoException
    {
        return new NotificacaoResultadoIndeterminadoException("Resultado desconhecido ao enviar por {$canal}: {$motivo}");
    }

    public static function transferenciaNaoAutorizada(string $canal): self
    {
        return new self("Envio por {$canal} bloqueado: falta configurar a autorização da APD para a transferência internacional de dados (ATELIER_APD_AUTORIZACAO).");
    }

    public static function naoConfigurado(string $canal): self
    {
        return new self("O canal \"{$canal}\" não está configurado (faltam credenciais no .env) — ver README do Atelier.");
    }

    public static function contactoEmFalta(string $canal): self
    {
        return new self("O cliente não tem contacto guardado para o canal \"{$canal}\".");
    }
}
