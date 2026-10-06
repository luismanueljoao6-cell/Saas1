<?php

namespace Modules\Atelier\Services;

use Modules\Atelier\Models\Medida;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Faturacao\Models\Cliente;

/**
 * Nunca atualiza uma Medida existente — regista sempre uma nova linha, para
 * que o "Histórico de alterações de medidas ao longo do tempo" pedido no
 * requisito A.1 seja, por construção, a lista de todas as linhas por
 * cliente_id (ver Medida::camposMedida() e o índice em
 * (empresa_id, cliente_id) na migration).
 */
class MedidaService
{
    /**
     * @param  array<string, float|null>  $valores  Chaves de Medida::camposMedida(); qualquer campo omitido fica null.
     */
    public function registar(Empresa $empresa, Cliente $cliente, array $valores, ?string $observacoes, ?User $registadoPor): Medida
    {
        $dados = array_intersect_key($valores, array_flip(Medida::camposMedida()));

        return Medida::create([
            'empresa_id' => $empresa->id,
            'cliente_id' => $cliente->id,
            ...$dados,
            'observacoes' => $observacoes,
            'registado_por_id' => $registadoPor?->id,
        ]);
    }

    public function maisRecentePara(Cliente $cliente): ?Medida
    {
        return Medida::where('cliente_id', $cliente->id)->latest('id')->first();
    }

    public function historicoPara(Cliente $cliente)
    {
        return Medida::where('cliente_id', $cliente->id)->latest('id')->get();
    }
}
