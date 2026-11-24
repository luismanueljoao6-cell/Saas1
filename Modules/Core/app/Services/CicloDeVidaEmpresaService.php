<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;

/**
 * Eliminação lógica e restauro de uma empresa.
 *
 * Nunca apaga dados: só marca/desmarca deleted_at (retenção de dados fiscais e
 * NIF reservado). Enquanto a empresa está eliminada, o middleware 'tenant' e o
 * login recusam os seus utilizadores (ver IdentificarTenant e LoginRequest).
 *
 * Ambas as operações são idempotentes e seguras com pedidos simultâneos: a linha
 * da empresa fica bloqueada durante a transação.
 */
class CicloDeVidaEmpresaService
{
    /**
     * @return bool true se esta chamada eliminou; false se já estava eliminada
     *
     * @throws ModelNotFoundException se a empresa não existir
     */
    public function eliminar(int $empresaId): bool
    {
        return DB::transaction(function () use ($empresaId): bool {
            $empresa = $this->bloquear($empresaId);

            if ($empresa->trashed()) {
                return false;
            }

            $empresa->delete();

            Log::warning('Empresa eliminada logicamente.', [
                'empresa_id' => $empresa->id,
            ]);

            return true;
        });
    }

    /**
     * @return bool true se esta chamada restaurou; false se já estava ativa
     *
     * @throws ModelNotFoundException se a empresa não existir
     */
    public function restaurar(int $empresaId): bool
    {
        return DB::transaction(function () use ($empresaId): bool {
            $empresa = $this->bloquear($empresaId);

            if (! $empresa->trashed()) {
                return false;
            }

            $empresa->restore();

            Log::warning('Empresa restaurada.', [
                'empresa_id' => $empresa->id,
            ]);

            return true;
        });
    }

    protected function bloquear(int $empresaId): Empresa
    {
        return Empresa::withTrashed()->lockForUpdate()->findOrFail($empresaId);
    }
}
