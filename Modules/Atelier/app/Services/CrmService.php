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
 *
 * CONSENTIMENTO (Lei n.º 22/11): marketing é OPT-IN. Só recebe quem tem um
 * ClienteCrm com consentimento válido (aceita_marketing, data de
 * consentimento registada e sem revogação). Cliente sem linha CRM = sem
 * consentimento. Todas as consultas filtram explicitamente por empresa_id
 * porque estes métodos também correm em jobs, sem tenant definido.
 */
class CrmService
{
    public function aniversariantesHoje(int $empresaId): Collection
    {
        $hoje = now();

        $clienteIds = $this->consentimentos($empresaId)
            ->whereNotNull('data_nascimento')
            ->where(function ($q) use ($hoje) {
                $q->where(fn ($d) => $d->whereMonth('data_nascimento', $hoje->month)->whereDay('data_nascimento', $hoje->day));

                // Quem faz anos a 29/02 celebra a 28/02 nos anos não bissextos.
                if ($hoje->month === 2 && $hoje->day === 28 && ! $hoje->isLeapYear()) {
                    $q->orWhere(fn ($d) => $d->whereMonth('data_nascimento', 2)->whereDay('data_nascimento', 29));
                }
            })
            ->pluck('cliente_id');

        return $this->clientes($empresaId, $clienteIds);
    }

    /** Clientes com pelo menos um pedido, mas nenhum nos últimos $meses (e com consentimento). */
    public function clientesInativos(int $empresaId, int $meses): Collection
    {
        $dataLimite = now()->subMonths($meses);

        $idsInativos = Pedido::query()
            ->where('empresa_id', $empresaId)
            ->select('cliente_id')
            ->groupBy('cliente_id')
            ->havingRaw('MAX(created_at) < ?', [$dataLimite])
            ->pluck('cliente_id');

        $comConsentimento = $this->consentimentos($empresaId)->pluck('cliente_id');

        return $this->clientes($empresaId, $idsInativos->intersect($comConsentimento));
    }

    public function resolverSegmento(Campanha $campanha): Collection
    {
        $empresaId = (int) $campanha->empresa_id;

        return match ($campanha->segmento) {
            'todos' => $this->clientes($empresaId, $this->consentimentos($empresaId)->pluck('cliente_id')),
            'aniversariantes_mes' => $this->clientes(
                $empresaId,
                $this->consentimentos($empresaId)
                    ->whereNotNull('data_nascimento')
                    ->whereMonth('data_nascimento', now()->month)
                    ->pluck('cliente_id'),
            ),
            'inativos' => $this->clientesInativos($empresaId, (int) config('atelier.meses_inatividade_reativacao')),
            default => collect(),
        };
    }

    /** Registos CRM com consentimento de marketing válido (data registada, não revogado). */
    protected function consentimentos(int $empresaId)
    {
        return ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->where('aceita_marketing', true)
            ->whereNotNull('consentimento_em')
            ->whereNull('consentimento_revogado_em');
    }

    protected function clientes(int $empresaId, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Cliente::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ids->unique()->values())
            ->get();
    }
}
