<?php

namespace Modules\Core\Services;

use DomainException;

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
     * O tenant e o bypass anteriores são sempre repostos no fim, mesmo com exceção.
     */
    public function semTenant(callable $callback): mixed
    {
        $anterior = $this->tenantId;
        $bypassAnterior = $this->bypassGlobal;

        $this->tenantId = null;
        $this->bypassGlobal = true;

        try {
            return $callback();
        } finally {
            $this->tenantId = $anterior;
            $this->bypassGlobal = $bypassAnterior;
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
}
