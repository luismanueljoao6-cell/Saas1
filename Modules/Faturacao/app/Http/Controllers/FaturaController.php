<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Http\Requests\GuardarFaturaRequest;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Services\AgtCorrecaoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use Throwable;

/**
 * {fatura} chega como int (sem route model binding): o binding corre antes
 * do middleware 'tenant' e a TenantScope fail-closed devolveria sempre 404.
 */
class FaturaController extends Controller
{
    public function __construct(
        protected FaturaService $faturaService,
        protected NotaCreditoDebitoService $notaService,
        protected AgtCorrecaoService $correcoes,
        protected ReciboService $recibos,
    ) {}

    public function index(): View
    {
        return view('faturacao::faturas.index', [
            'faturas' => Fatura::with('cliente', 'agtSubmissao')->latest('id')->paginate(20),
        ]);
    }

    public function criar(): View
    {
        return view('faturacao::faturas.criar', [
            'clientes' => Cliente::orderBy('nome')->get(),
            'produtos' => Produto::ativos()->orderBy('nome')->get(),
            'taxaIvaGeral' => (float) config('faturacao.taxa_iva_geral'),
            ...$this->dadosDeIsencao(),
            // Depois de um erro, o formulário volta com as linhas que o utilizador tinha escrito.
            'linhasIniciais' => collect((array) old('linhas', []))->map(fn ($linha) => [
                'produto_id' => $linha['produto_id'] ?? null,
                'descricao' => (string) ($linha['descricao'] ?? ''),
                'quantidade' => $linha['quantidade'] ?? 1,
                'preco_unitario' => $linha['preco_unitario'] ?? 0,
                'taxa_iva' => $linha['taxa_iva'] ?? config('faturacao.taxa_iva_geral'),
                'motivo_isencao' => $linha['motivo_isencao'] ?? '',
            ])->values()->all(),
        ]);
    }

    /**
     * Para o formulário: motivos de isenção (só há seletor se a taxa 0% for permitida).
     *
     * @return array{motivosIsencao: array<string, string>, aceitaIsencao: bool}
     */
    protected function dadosDeIsencao(): array
    {
        return [
            'motivosIsencao' => collect((array) config('faturacao.motivos_isencao', []))
                ->map(fn (array $motivo, string $codigo) => "{$codigo} — {$motivo['descricao']}")
                ->all(),
            'aceitaIsencao' => in_array(0.0, array_map('floatval', (array) config('faturacao.taxas_iva_permitidas', [])), true),
        ];
    }

    public function guardar(GuardarFaturaRequest $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403, 'Utilizador sem empresa associada.');

        $this->garantirQuePodeEmitirFaturas($empresa);

        $cliente = Cliente::findOrFail($request->validated('cliente_id'));

        try {
            $fatura = $this->faturaService->criarRascunho(
                $empresa,
                $cliente,
                $request->validated('linhas'),
                $request->validated('observacoes'),
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            // Regras de negócio (ex.: linha isenta sem motivo): mensagens seguras para o utilizador.
            return back()->withInput()->withErrors(['fatura' => $e->getMessage()]);
        }

        return redirect()->route('faturacao.faturas.mostrar', $fatura)
            ->with('sucesso', 'Rascunho criado. Revê os valores antes de emitir.');
    }

    public function mostrar(int $fatura): View
    {
        $modelo = Fatura::with('linhas', 'cliente', 'notasCreditoDebito', 'recibos', 'agtSubmissao', 'correcao')->findOrFail($fatura);

        return view('faturacao::faturas.mostrar', [
            'fatura' => $modelo,
            // Só faz sentido (e só se calcula) para faturas emitidas: recebido, creditado e valor em dívida.
            'resumo' => $modelo->estaEmitido() ? $this->recibos->resumoDaFatura($modelo) : null,
        ]);
    }

    /**
     * Cria o rascunho da fatura que corrige uma fatura invalidada/rejeitada pela AGT (regra E46:
     * a correção é um documento novo). Não emite nada: o rascunho segue o fluxo normal.
     */
    public function corrigir(int $fatura): RedirectResponse
    {
        $modelo = Fatura::with('agtSubmissao')->findOrFail($fatura);

        if (! $modelo->agtSubmissao) {
            return back()->with('erro', 'Esta fatura não tem registo de envio à AGT: não há nada a corrigir.');
        }

        try {
            $rascunho = $this->correcoes->criarRascunhoDeCorrecao($modelo->agtSubmissao);
        } catch (\DomainException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('faturacao.faturas.editar', $rascunho->id)
            ->with('sucesso', 'Rascunho de correção criado. Revê os valores e emite-o: segue para a AGT como correção da fatura rejeitada.');
    }

    public function editar(int $fatura): View|RedirectResponse
    {
        $modelo = Fatura::with('linhas')->findOrFail($fatura);

        if ($modelo->estado !== 'rascunho') {
            return redirect()->route('faturacao.faturas.mostrar', $modelo->id)
                ->with('erro', 'Só é possível editar faturas em rascunho.');
        }

        return view('faturacao::faturas.criar', [
            'fatura' => $modelo,
            'clientes' => Cliente::orderBy('nome')->get(),
            'produtos' => Produto::ativos()->orderBy('nome')->get(),
            'taxaIvaGeral' => (float) config('faturacao.taxa_iva_geral'),
            ...$this->dadosDeIsencao(),
            'linhasIniciais' => $modelo->linhas->map(fn ($linha) => [
                'produto_id' => $linha->produto_id,
                'descricao' => $linha->descricao,
                'quantidade' => (float) $linha->quantidade,
                'preco_unitario' => (float) $linha->preco_unitario,
                'taxa_iva' => (float) $linha->taxa_iva,
                'motivo_isencao' => $linha->motivo_isencao ?? '',
            ])->values()->all(),
        ]);
    }

    public function atualizar(GuardarFaturaRequest $request, int $fatura): RedirectResponse
    {
        $modelo = Fatura::findOrFail($fatura);

        $this->garantirQuePodeEmitirFaturas($modelo->empresa);

        $cliente = Cliente::findOrFail($request->validated('cliente_id'));

        try {
            $this->faturaService->atualizarRascunho(
                $modelo,
                $cliente,
                $request->validated('linhas'),
                $request->validated('observacoes'),
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['fatura' => $e->getMessage()]);
        }

        return redirect()->route('faturacao.faturas.mostrar', $modelo->id)
            ->with('sucesso', 'Rascunho atualizado. Revê os valores antes de emitir.');
    }

    public function eliminar(int $fatura): RedirectResponse
    {
        $modelo = Fatura::findOrFail($fatura);

        try {
            $this->faturaService->eliminarRascunho($modelo);
        } catch (\DomainException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('faturacao.faturas.index')
            ->with('sucesso', 'Rascunho eliminado.');
    }

    /**
     * Anulação TOTAL por nota de crédito: criar + emitir na MESMA transação.
     * Se a emissão falhar não fica um rascunho de nota a bloquear nova
     * tentativa ("esta fatura já tem uma nota de crédito associada").
     */
    public function anular(Request $request, int $fatura): RedirectResponse
    {
        $dados = $request->validate([
            // A AGT recebe no máximo 60 caracteres do motivo: não se aceita mais, para não truncar em silêncio.
            'motivo' => ['required', 'string', 'min:3', 'max:60'],
        ]);

        $modelo = Fatura::findOrFail($fatura);

        $this->garantirQuePodeEmitirFaturas($modelo->empresa);

        try {
            $nota = DB::transaction(fn () => $this->notaService->emitir(
                $this->notaService->criarParaAnularFatura($modelo, $dados['motivo'])
            ), attempts: 3);
        } catch (\DomainException|\InvalidArgumentException|EmissaoNaoPermitidaException $e) {
            // Mensagens de regra de negócio: seguras para o utilizador.
            return back()->with('erro', $e->getMessage());
        } catch (AssinaturaFiscalException) {
            return back()->with('erro', 'Não foi possível assinar o documento. A equipa técnica foi notificada.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('erro', 'Ocorreu um erro ao emitir a nota de crédito. Tenta novamente.');
        }

        return redirect()->route('faturacao.faturas.mostrar', $modelo->id)
            ->with('sucesso', "Nota de crédito {$nota->numero_documento} emitida: a fatura ficou anulada.");
    }

    public function emitir(int $fatura): RedirectResponse
    {
        $modelo = Fatura::findOrFail($fatura);

        $this->garantirQuePodeEmitirFaturas($modelo->empresa);

        try {
            $this->faturaService->emitir($modelo);
        } catch (\DomainException|\InvalidArgumentException $e) {
            // Mensagens de regra de negócio: seguras para o utilizador.
            return back()->with('erro', $e->getMessage());
        } catch (AssinaturaFiscalException) {
            // Detalhe técnico já está no log (o serviço regista-o).
            return back()->with('erro', 'Não foi possível assinar o documento. A equipa técnica foi notificada.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('erro', 'Ocorreu um erro ao emitir a fatura. Tenta novamente.');
        }

        return redirect()->route('faturacao.faturas.mostrar', $modelo)
            ->with('sucesso', 'Fatura emitida com sucesso.');
    }

    protected function garantirQuePodeEmitirFaturas(Empresa $empresa): void
    {
        abort_unless(
            $empresa->podeEmitirFaturas(),
            402,
            'A tua empresa está no período de tolerância — podes consultar documentos antigos, mas não emitir novos até regularizares a subscrição.'
        );
    }
}
