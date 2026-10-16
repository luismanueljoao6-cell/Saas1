<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Support\Facades\DB;
use Modules\EstudioMusica\Models\AceiteTermos;
use Modules\EstudioMusica\Models\CampanhaEnviada;
use Modules\EstudioMusica\Models\CupomDesconto;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\Faturacao\Models\Cliente;

/**
 * Direitos do titular dos dados (Lei n.º 22/11): acesso e eliminação do
 * que o MÓDULO ESTÚDIO guarda sobre um artista. Corre dentro do tenant
 * já definido (ver os comandos `estudiomusica:exportar-dados-artista` e
 * `estudiomusica:limpar-dados-artista`).
 *
 * Nunca apaga o Cliente nem documentos fiscais (faturas, recibos): a lei
 * fiscal obriga a conservá-los. Também não apaga os ficheiros de áudio
 * dos projetos — são trabalho entregue/contratado, e o seu destino é uma
 * decisão do estúdio com o artista.
 */
class DadosPessoaisService
{
    /** Colunas que nunca saem numa exportação (credenciais). */
    protected const OCULTAS = ['token_portal', 'token_portal_expira_em'];

    /**
     * @return array<string, mixed>
     */
    public function exportar(Cliente $cliente): array
    {
        $ficha = collect($cliente->getAttributes())->except(self::OCULTAS)->all();

        return [
            'gerado_em' => now()->toIso8601String(),
            'cliente' => $ficha,
            'projetos' => ProjetoMusical::query()
                ->where('cliente_id', $cliente->id)
                ->with('faixas.versoes')
                ->get()
                ->map(fn (ProjetoMusical $p) => [
                    'nome' => $p->nome,
                    'estado' => $p->estado,
                    'tipo_cobranca' => $p->tipo_cobranca,
                    'faixas' => $p->faixas->map(fn ($f) => [
                        'nome' => $f->nome,
                        'versoes' => $f->versoes->map(fn ($v) => [
                            'rotulo' => $v->rotulo,
                            'estado' => $v->estado,
                            'enviada_em' => $v->enviada_em?->toIso8601String(),
                        ])->all(),
                    ])->all(),
                ])->all(),
            'sessoes' => SessaoEstudio::query()
                ->where('cliente_id', $cliente->id)
                ->get(['tipo_servico', 'inicio_previsto', 'fim_previsto', 'inicio_real', 'fim_real', 'estado'])
                ->toArray(),
            'pedidos_orcamento' => $this->solicitacoesDe($cliente)->get()->toArray(),
            'reclamacoes' => $this->reclamacoesDe($cliente)->get(['id', 'assunto', 'descricao', 'estado', 'resposta', 'created_at'])->toArray(),
            'termos_aceites' => AceiteTermos::query()->where('cliente_id', $cliente->id)->get(['termos_estudio_id', 'aceite_em'])->toArray(),
            'mensagens_promocionais_enviadas' => CampanhaEnviada::query()
                ->where('cliente_id', $cliente->id)
                ->get(['tipo', 'referencia', 'enviada_em'])
                ->toArray(),
            'cupons' => CupomDesconto::query()
                ->where('cliente_id', $cliente->id)
                ->get(['codigo', 'percentual_desconto', 'motivo', 'valido_ate', 'usado_em'])
                ->toArray(),
        ];
    }

    /**
     * @return array{pedidos_orcamento: int, reclamacoes: int, mensagens: int, cupons: int}
     */
    public function limpar(Cliente $cliente): array
    {
        return DB::transaction(function () use ($cliente) {
            $cliente->forceFill([
                'nome_artistico' => null,
                'genero_musical' => null,
                'integrantes_banda' => null,
                'links_redes_sociais' => null,
                'data_nascimento' => null,
                'aceita_marketing' => false,
                'aceita_marketing_em' => null,
                // Revoga o acesso ao portal.
                'token_portal' => null,
                'token_portal_expira_em' => null,
            ])->save();

            return [
                'pedidos_orcamento' => $this->solicitacoesDe($cliente)->delete(),
                'reclamacoes' => $this->reclamacoesDe($cliente)->delete(),
                'mensagens' => CampanhaEnviada::query()->where('cliente_id', $cliente->id)->delete(),
                'cupons' => CupomDesconto::query()->where('cliente_id', $cliente->id)->delete(),
            ];
        });
    }

    /** Reclamações feitas com o mesmo e-mail ou telefone do cliente (vazio se não houver nenhum dos dois). */
    protected function reclamacoesDe(Cliente $cliente)
    {
        $query = Reclamacao::query();

        if (! $cliente->email && ! $cliente->telefone) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($cliente) {
            if ($cliente->email) {
                $q->orWhere('email', $cliente->email);
            }

            if ($cliente->telefone) {
                $q->orWhere('telefone', $cliente->telefone);
            }
        });
    }

    /**
     * Pedidos de orçamento feitos com o mesmo e-mail ou telefone do cliente.
     * Sem nenhum dos dois não há como associar — devolve uma query vazia
     * (e não "todos os pedidos").
     */
    protected function solicitacoesDe(Cliente $cliente)
    {
        $query = SolicitacaoOrcamento::query();

        if (! $cliente->email && ! $cliente->telefone) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($cliente) {
            if ($cliente->email) {
                $q->orWhere('email', $cliente->email);
            }

            if ($cliente->telefone) {
                $q->orWhere('telefone', $cliente->telefone);
            }
        });
    }
}
