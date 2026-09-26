<?php

namespace Modules\Core\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;

/**
 * Global Scope responsável por impedir o vazamento de dados entre empresas.
 *
 * Decisão de segurança deliberada (fail-closed): se, por qualquer razão, o
 * TenantManager não tiver uma empresa definida no momento da query — e não
 * estivermos num bypass explícito de super admin — a scope filtra a query
 * para devolver ZERO resultados, em vez de deixar passar a query sem filtro.
 *
 * Isto significa que um middleware esquecido numa rota nova falha de forma
 * "ruidosa" (a página fica vazia, e regista-se um warning no log) em vez de
 * expor silenciosamente dados de todas as empresas. Ver TenantManager para o
 * mecanismo de bypass intencional (semTenant()).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantManager $tenantManager */
        $tenantManager = app(TenantManager::class);

        if ($tenantManager->emBypass()) {
            return;
        }

        if ($tenantManager->has()) {
            $builder->where($model->qualifyColumn('empresa_id'), $tenantManager->id());

            return;
        }

        Log::warning('TenantScope aplicada sem tenant definido — a bloquear a query por segurança.', [
            'model' => $model::class,
        ]);

        // Fail-closed: nenhuma empresa definida e nenhum bypass explícito.
        $builder->whereRaw('1 = 0');
    }
}
