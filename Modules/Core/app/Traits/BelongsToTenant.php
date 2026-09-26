<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Empresa;
use Modules\Core\Scopes\TenantScope;
use Modules\Core\Services\TenantManager;

/**
 * Trait a usar em QUALQUER model de negócio que pertença a uma empresa
 * (faturas, clientes, encomendas, sessões de estúdio, etc.), em qualquer
 * módulo do sistema. Garante duas coisas:
 *
 * 1. Todas as queries a este model são automaticamente filtradas pela
 *    empresa atual (via TenantScope).
 * 2. Ao criar um novo registo sem empresa_id explícito, esta é preenchida
 *    automaticamente a partir do TenantManager — reduz o risco de um
 *    developer esquecer-se de o fazer manualmente.
 *
 * Uso:
 *
 *     class Fatura extends Model
 *     {
 *         use BelongsToTenant;
 *     }
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (! $model->empresa_id) {
                $tenantManager = app(TenantManager::class);

                if ($tenantManager->has()) {
                    $model->empresa_id = $tenantManager->id();
                }
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
