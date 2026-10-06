<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Collection;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Faturacao\Models\Cliente;

/**
 * Resolve "segmentos" de clientes para o CRM/Marketing (requisito D).
 * Consulta sempre a partir de Pedido/ClienteCrm (models do Atelier) e nunca
 * acrescenta relações a Modules\Faturacao\Models\Cliente — mantém o módulo
 * Faturacao intocado (ver ClienteCrm e a migration correspondente).
 */
class CrmService
{
    public function aniversariantesHoje(int $empresaId): Collection
    {
        $hoje = now();

        $clienteIds = ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->where('aceita_marketing', true)
            ->whereNotNull('data_nascimento')
            ->whereMonth('data_nascimento', $hoje->month)
            ->whereDay('data_nascimento', $hoje->day)
            ->pluck('cliente_id');

        return Cliente::whereIn('id', $clienteIds)->get();
    }

    /** Clientes com pelo menos um pedido, mas nenhum nos últimos $meses. */
    public function clientesInativos(int $empresaId, int $meses): Collection
    {
        $dataLimite = now()->subMonths($meses);

        $todosComPedido = Pedido::where('empresa_id', $empresaId)->pluck('cliente_id')->unique();
        $comPedidoRecente = Pedido::where('empresa_id', $empresaId)
            ->where('created_at', '>=', $dataLimite)
            ->pluck('cliente_id')
            ->unique();

        $idsInativos = $todosComPedido->diff($comPedidoRecente);

        return $this->filtrarOptIn(Cliente::whereIn('id', $idsInativos)->get());
    }

    public function resolverSegmento(Campanha $campanha): Collection
    {
        return match ($campanha->segmento) {
            'todos' => $this->filtrarOptIn(Cliente::where('empresa_id', $campanha->empresa_id)->get()),
            'aniversariantes_mes' => $this->filtrarOptIn($this->clientesComAniversarioNoMes($campanha->empresa_id)),
            'inativos' => $this->clientesInativos($campanha->empresa_id, (int) config('atelier.meses_inatividade_reativacao')),
            default => collect(),
        };
    }

    protected function clientesComAniversarioNoMes(int $empresaId): Collection
    {
        $clienteIds = ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->whereNotNull('data_nascimento')
            ->whereMonth('data_nascimento', now()->month)
            ->pluck('cliente_id');

        return Cliente::whereIn('id', $clienteIds)->get();
    }

    protected function filtrarOptIn(Collection $clientes): Collection
    {
        $optOutIds = ClienteCrm::whereIn('cliente_id', $clientes->pluck('id'))
            ->where('aceita_marketing', false)
            ->pluck('cliente_id');

        return $clientes->whereNotIn('id', $optOutIds)->values();
    }
}
