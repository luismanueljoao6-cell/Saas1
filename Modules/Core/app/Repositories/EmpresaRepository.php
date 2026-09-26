<?php

namespace Modules\Core\Repositories;

use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Repositories\Contracts\EmpresaRepositoryInterface;
use Throwable;

class EmpresaRepository implements EmpresaRepositoryInterface
{
    public function encontrarPorId(int $id): ?Empresa
    {
        return Empresa::find($id);
    }

    public function encontrarPorNif(string $nif): ?Empresa
    {
        return Empresa::where('nif', $nif)->first();
    }

    public function atualizar(Empresa $empresa, array $dados): Empresa
    {
        try {
            $empresa->fill($dados);
            $empresa->save();

            return $empresa->refresh();
        } catch (Throwable $e) {
            Log::error('Falha ao atualizar dados da empresa', [
                'empresa_id' => $empresa->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
