<?php

namespace Modules\Core\Services;

/**
 * Guarda, durante o ciclo de vida de um pedido (ou de um comando artisan/job),
 * qual é a empresa "atual". É ligado como singleton no CoreServiceProvider,
 * pelo que app(TenantManager::class) devolve sempre a mesma instância dentro
 * do mesmo pedido.
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
     * Permite a um super admin, de forma explícita e temporária, ver dados
     * de todas as empresas (ex.: painel de administração da plataforma).
     * Deve ser sempre emparelhado com semTenant() dentro de um try/finally
     * para garantir que o bypass não "escapa" para outros pedidos.
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

    public function emBypass(): bool
    {
        return $this->bypassGlobal;
    }
}
