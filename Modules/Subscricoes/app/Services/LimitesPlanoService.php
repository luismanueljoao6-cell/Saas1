<?php

namespace Modules\Subscricoes\Services;

use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Models\Subscricao;

/**
 * Traduz o plano da empresa nos limites que o resto do sistema consulta
 * (ver Modules\Core\Contracts\LimitesDaEmpresa). Sem subscrição ativa
 * (período experimental, por exemplo), valem os limites de
 * config('subscricoes.limites_sem_plano').
 */
class LimitesPlanoService implements LimitesDaEmpresa
{
    public function __construct(protected TenantManager $tenantManager) {}

    public function limite(Empresa $empresa, string $chave): ?int
    {
        $limites = $this->limitesAtuais($empresa);

        if (! array_key_exists($chave, $limites) || $limites[$chave] === null) {
            return null;
        }

        return (int) $limites[$chave];
    }

    /**
     * @return array<string, int|null>
     */
    protected function limitesAtuais(Empresa $empresa): array
    {
        // Filtra por empresa_id explícito, por isso não depende do tenant
        // "ambiente" (pode não haver nenhum: jobs, testes, consola).
        $subscricao = $this->tenantManager->semTenant(fn () => Subscricao::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'ativa')
            ->with('plano')
            ->latest('id')
            ->first());

        if ($subscricao?->plano) {
            return $subscricao->plano->limites ?? [];
        }

        return config('subscricoes.limites_sem_plano', []);
    }
}
