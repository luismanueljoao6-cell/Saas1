<?php

namespace Modules\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
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
 * 2. Ao criar um novo registo, empresa_id é determinado pelo TenantManager
 *    sempre que existe um tenant identificado — nunca por um valor vindo
 *    de fora (formulário, payload de API, etc.), mesmo que alguém o tente
 *    definir explicitamente. Isto torna impossível, não só improvável,
 *    um pedido forjado criar um registo na empresa errada. Só quando NÃO
 *    há tenant identificado (bypass explícito via TenantManager::semTenant(),
 *    ou contexto de consola/seeder) é que o valor dado explicitamente é
 *    respeitado — é isso que serviços como o NumeracaoService fazem, de
 *    propósito, ao operar entre empresas.
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

        static::updating(function ($model): void {
            if ($model->isDirty('empresa_id') && $model->getOriginal('empresa_id') !== null) {
                throw new \LogicException('empresa_id não pode ser alterado depois de o registo ser criado.');
            }
        });

        static::creating(function ($model): void {
            $tenantManager = app(TenantManager::class);

            if (! $tenantManager->has()) {
                // Sem tenant identificado: respeita o que o chamador deu
                // explicitamente (ou nada, se também não deu nada).
                return;
            }

            if ($model->empresa_id && (int) $model->empresa_id !== $tenantManager->id()) {
                Log::warning('Tentativa de criar registo com empresa_id fora do tenant atual — ignorada e substituída.', [
                    'model' => $model::class,
                    'empresa_id_pedido' => $model->empresa_id,
                    'tenant_atual' => $tenantManager->id(),
                ]);
            }

            $model->empresa_id = $tenantManager->id();
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
