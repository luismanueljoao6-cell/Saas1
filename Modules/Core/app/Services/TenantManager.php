<?php

namespace Modules\\Core\\Services;

use DomainException;
use InvalidArgumentException;
use Spatie\\Permission\\PermissionRegistrar;

/**
 * Guarda, durante o ciclo de vida de um pedido (ou de um comando artisan/job),
 * qual é a empresa "atual". É registado como scoped no CoreServiceProvider,
 * pelo que a mesma instância é partilhada durante o ciclo de vida atual e
 * renovada entre pedidos/jobs em runtimes persistentes.
 *
 * Propositadamente simples e sem dependência direta de Auth ou de sessão:
 * quem decide QUANDO definir o tenant é o middleware IdentificarTenant (ou,
 * em jobs/commands, o próprio código que despacha a tarefa). Isto mantém a
 * TenantScope e o BelongsToTenant desacoplados do fluxo HTTP.
 */
class TenantManager
{
    protected ?int $tenantId = null;

    protected bool $bypassGlobal = false;

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    /**
     * Executa o callback fora do contexto de qualquer empresa, de forma explícita
     * e temporária (painel de administração da plataforma, serviços de sistema).
     * Limpa também o team do Spatie para que operações globais de papéis e
     * permissões não herdem silenciosamente a empresa anterior.
     *
     * O tenant, o bypass e o team anteriores são sempre repostos no fim,
     * incluindo quando o callback lança uma exceção ou existem chamadas aninhadas.
     */
    public function semTenant(callable $callback): mixed
    {
        $anterior = $this->tenantId;
        $bypassAnterior = $this->bypassGlobal;
        $registrar = class_exists(PermissionRegistrar::class) ? app(PermissionRegistrar::class) : null;
        $teamAnterior = $registrar?->getPermissionsTeamId();

        try {
            $this->tenantId = null;
            $this->bypassGlobal = true;
            $registrar?->setPermissionsTeamId(null);

            return $callback();
        } finally {
            $this->tenantId = $anterior;
            $this->bypassGlobal = $bypassAnterior;
            $registrar?->setPermissionsTeamId($teamAnterior);
        }
    }

    /**
     * Regra única: se há um tenant ativo, a operação tem de ser sobre essa empresa.
     * Sem tenant ativo (consola, jobs, semTenant) não restringe.
     */
    public function garantirEmpresa(int $empresaId, string $mensagem = 'A operação não pertence à empresa do contexto atual.'): void
    {
        if ($this->has() && $this->tenantId !== $empresaId) {
            throw new DomainException($mensagem);
        }
    }

    public function emBypass(): bool
    {
        return $this->bypassGlobal;
    }

    /**
     * Executa o callback COM a empresa indicada como contexto (operações por
     * empresa: jobs e comandos que percorrem empresas). Ao contrário de
     * semTenant(), só vê e escreve dados dessa empresa. Sai de qualquer bypass
     * durante o callback e repõe SEMPRE, mesmo com exceção, o tenant, o bypass
     * e o team do Spatie Permission anteriores.
     */
    public function comEmpresa(int $empresaId, callable $callback): mixed
    {
        if ($empresaId < 1) {
            throw new InvalidArgumentException('O id da empresa tem de ser um inteiro positivo.');
        }

        $anterior = $this->tenantId;
        $bypassAnterior = $this->bypassGlobal;
        $registrar = class_exists(PermissionRegistrar::class) ? app(PermissionRegistrar::class) : null;
        $teamAnterior = $registrar?->getPermissionsTeamId();

        $this->tenantId = $empresaId;
        $this->bypassGlobal = false;
        $registrar?->setPermissionsTeamId($empresaId);

        try {
            return $callback();
        } finally {
            $this->tenantId = $anterior;
            $this->bypassGlobal = $bypassAnterior;
            $registrar?->setPermissionsTeamId($teamAnterior);
        }
    }
}
