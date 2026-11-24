<?php

namespace Modules\\Core\\Services;

use Illuminate\\Database\\Eloquent\\Model;
use InvalidArgumentException;
use Modules\\Core\\Contracts\\AuditLoggerInterface;

/** Adaptador do Spatie Activitylog, sem expor o pacote aos módulos consumidores. */
class AuditLogger implements AuditLoggerInterface
{
    public function record(
        string $description,
        array $properties = [],
        ?Model $subject = null,
        ?Model $causer = null,
        string $logName = 'core',
    ): void {
        if (trim($description) === '') {
            throw new InvalidArgumentException('A descrição do evento de auditoria é obrigatória.');
        }

        if (! preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $logName)) {
            throw new InvalidArgumentException('O nome do registo de auditoria é inválido.');
        }

        $logger = activity($logName)->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $logger->log($description);
    }
}
