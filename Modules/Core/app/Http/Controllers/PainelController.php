<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Models\Pedido;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Fatura;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\Subscricoes\Models\Subscricao;

class PainelController extends Controller
{
    public function __invoke(): View
    {
        $utilizador = auth()->user();
        /** @var Empresa|null $empresa */
        $empresa = $utilizador?->empresa;
        $indisponivel = ['valor' => '—', 'nota' => 'Dados indisponíveis'];

        $kpis = [
            'receita' => ['rotulo' => 'Faturado este mês', ...$indisponivel, 'icone' => 'dinheiro'],
            'pedidos' => ['rotulo' => 'Pedidos em curso', ...$indisponivel, 'icone' => 'pedidos'],
            'sessoes' => ['rotulo' => 'Sessões nos próximos 7 dias', ...$indisponivel, 'icone' => 'calendario'],
            'plano' => ['rotulo' => 'Utilizadores do plano', ...$indisponivel, 'icone' => 'medidor'],
        ];

        $faturasRecentes = collect();
        $pedidosRecentes = collect();

        // A conta sem empresa é uma conta de plataforma: não agrega dados de tenants.
        if ($empresa) {
            if ($empresa->temServico('faturacao')) {
                $inicioMes = now()->startOfMonth()->toDateString();
                $fimMes = now()->endOfMonth()->toDateString();

                $faturasEmitidas = Fatura::query()
                    ->where('estado', 'emitida')
                    ->whereBetween('data_emissao', [$inicioMes, $fimMes]);

                $total = (string) (clone $faturasEmitidas)->sum('valor_total');
                $kpis['receita'] = [
                    'rotulo' => 'Faturado este mês',
                    'valor' => number_format((float) $total, 2, ',', ' ').' AOA',
                    'nota' => (clone $faturasEmitidas)->count().' documento(s) emitido(s)',
                    'icone' => 'dinheiro',
                ];

                $faturasRecentes = Fatura::query()
                    ->with('cliente')
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Fatura $fatura) => (object) [
                        'id' => $fatura->id,
                        'numero' => $fatura->numero_documento ?: 'Rascunho #'.$fatura->id,
                        'cliente' => $fatura->cliente?->nome ?? 'Consumidor final',
                        'emissao' => $fatura->data_emissao,
                        'total' => $fatura->valor_total,
                        'estado' => $fatura->estado,
                    ]);
            }

            if ($empresa->temServico('atelier')) {
                $emCurso = Pedido::query()
                    ->whereNotIn('status', config('atelier.estados_finais', ['entregue', 'cancelado']));

                $kpis['pedidos'] = [
                    'rotulo' => 'Pedidos em curso',
                    'valor' => number_format((clone $emCurso)->count(), 0, ',', ' '),
                    'nota' => 'Pedidos ainda não entregues nem cancelados',
                    'icone' => 'pedidos',
                ];

                $pedidosRecentes = Pedido::query()
                    ->with('cliente')
                    ->whereNotIn('status', config('atelier.estados_finais', ['entregue', 'cancelado']))
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Pedido $pedido) => (object) [
                        'id' => $pedido->id,
                        'numero' => 'PED-'.$pedido->id,
                        'cliente' => $pedido->cliente?->nome ?? 'Cliente',
                        'peca' => $pedido->descricao,
                        'entrega' => $pedido->data_prevista_entrega,
                        'total' => $pedido->valor_orcamento,
                        'estado' => $pedido->statusRotulo(),
                    ]);
            }

            if ($empresa->temServico('estudio')) {
                $sessoes = SessaoEstudio::query()
                    ->whereIn('estado', ['agendada', 'confirmada', 'em_curso'])
                    ->whereBetween('inicio_previsto', [now(), now()->addDays(7)]);

                $kpis['sessoes'] = [
                    'rotulo' => 'Sessões nos próximos 7 dias',
                    'valor' => number_format((clone $sessoes)->count(), 0, ',', ' '),
                    'nota' => 'Agendadas, confirmadas ou em curso',
                    'icone' => 'calendario',
                ];
            }

            $subscricao = Subscricao::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('estado', ['ativa', 'pendente'])
                ->latest('id')
                ->first();

            $limites = $subscricao?->limites ?? [];
            $limiteUtilizadores = $limites['max_utilizadores'] ?? $limites['utilizadores'] ?? null;
            $utilizadoresAtivos = $empresa->utilizadores()->where('ativo', true)->count();

            $kpis['plano'] = [
                'rotulo' => 'Utilizadores do plano',
                'valor' => $limiteUtilizadores !== null
                    ? $utilizadoresAtivos.' / '.(int) $limiteUtilizadores
                    : number_format($utilizadoresAtivos, 0, ',', ' '),
                'nota' => $limiteUtilizadores !== null ? 'Utilizadores ativos' : 'Sem limite de utilizadores definido no plano',
                'icone' => 'medidor',
            ];

            if (is_numeric($limiteUtilizadores) && (int) $limiteUtilizadores > 0) {
                $kpis['plano']['progresso'] = min(100, (int) round($utilizadoresAtivos / (int) $limiteUtilizadores * 100));
                $kpis['plano']['limite'] = $utilizadoresAtivos.' de '.(int) $limiteUtilizadores.' utilizadores';
            }
        }

        return view('core::painel', compact('kpis', 'faturasRecentes', 'pedidosRecentes'));
    }
}
