<?php

namespace Modules\\Core\\Contracts;

use Illuminate\\Database\\Eloquent\\Model;

/**
 * Registo explícito de eventos relevantes de negócio e segurança.
 * Propriedades devem conter apenas dados necessários, nunca segredos,
 * credenciais, tokens ou identificadores pessoais desnecessários.
 */
interface AuditLoggerInterface
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $description,
        array $properties = [],
        ?Model $subject = null,
        ?Model $causer = null,
        string $logName = 'core',
    ): void;
}
