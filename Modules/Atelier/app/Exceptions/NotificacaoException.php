<?php

namespace Modules\Atelier\Exceptions;

use Exception;

class NotificacaoException extends Exception
{
    public static function falhaNoEnvio(string $canal, string $motivo): self
    {
        return new self("Falha ao enviar notificação por {$canal}: {$motivo}");
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
