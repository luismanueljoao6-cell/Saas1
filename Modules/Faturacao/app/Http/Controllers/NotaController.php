<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Http\Requests\GuardarNotaRequest;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Throwable;

/**
 * Notas de crédito e de débito. {nota} chega como int (sem route model binding): o binding
 * corre antes do middleware 'tenant' e a TenantScope fail-closed devolveria sempre 404.
 */
class NotaController extends Controller
{
    public function __construct(protected NotaCreditoDebitoService $notas) {}

    public function index(): View
    {
        return view('faturacao::notas.index', [
            'notas' => NotaCreditoDebito::with('cliente', 'agtSubmissao')->latest('id')->paginate(20),
        ]);
    }

    public function criar(Request $request): View|RedirectResponse
    {
        $tipo = $request->query('tipo') === 'debito' ? 'debito' : 'credito';
        $fatura = null;

        if ($request->filled('fatura')) {
            $fatura = Fatura::with('linhas')->find($request->integer('fatura'));

            if (! $fatura || ! $fatura->estaEmitido()) {
                return redirect()->route('faturacao.faturas.index')->with('erro', 'A fatura de origem tem de existir e estar emitida.');
            }
        } elseif ($tipo === 'credito') {
            return redirect()->route('faturacao.faturas.index')
                ->with('erro', 'Uma nota de crédito parte de uma fatura emitida: abre a fatura e escolhe a nota de crédito.');
        }

        // Depois de um erro, o formulário volta com o que o utilizador tinha escrito.
        $linhasAntigas = collect((array) old('linhas', []))->map(fn ($linha) => [
            'produto_id' => $linha['produto_id'] ?? null,
            'descricao' => (string) ($linha['descricao'] ?? ''),
            'quantidade' => $linha['quantidade'] ?? 1,
            'preco_unitario' => $linha['preco_unitario'] ?? 0,
            'taxa_iva' => $linha['taxa_iva'] ?? 0,
            'motivo_isencao' => $linha['motivo_isencao'] ?? '',
        ])->values()->all();

        // Nota de crédito: parte das linhas da fatura (reduz as quantidades para creditar só uma parte).
        $linhasDaFatura = $tipo === 'credito' && $fatura
            ? $fatura->linhas->map(fn ($linha) => [
                'produto_id' => $linha->produto_id,
                'descricao' => $linha->descricao,
                'quantidade' => (float) $linha->quantidade,
                'preco_unitario' => (float) $linha->preco_unitario,
                'taxa_iva' => (float) $linha->taxa_iva,
                'motivo_isencao' => $linha->motivo_isencao ?? '',
            ])->values()->all()
            : [];

        return view('faturacao::notas.criar', [
            'tipo' => $tipo,
            'fatura' => $fatura,
            'clientes' => $fatura ? collect() : Cliente::orderBy('nome')->get(['id', 'nome', 'nif']),
            'linhasIniciais' => $linhasAntigas !== [] ? $linhasAntigas : $linhasDaFatura,
            'taxaNaInterface' => $tipo === 'credito' || (bool) config('faturacao.nota_debito_liquida_iva', false),
        ]);
    }

    public function guardar(GuardarNotaRequest $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403, 'Utilizador sem empresa associada.');

        $tipo = $request->validated('tipo');
        $faturaId = $request->validated('fatura_id');
        $linhas = $request->validated('linhas');

        // Nota de débito sem IVA: a taxa não vem do formulário, é sempre 0.
        if ($tipo === 'debito' && ! config('faturacao.nota_debito_liquida_iva', false)) {
            $linhas = array_map(fn (array $linha) => [...$linha, 'taxa_iva' => 0, 'motivo_isencao' => null], $linhas);
        }

        // O cliente da nota é sempre o da fatura de origem.
        $clienteId = $faturaId !== null
            ? (int) Fatura::findOrFail($faturaId)->cliente_id
            : (int) $request->validated('cliente_id');

        try {
            $nota = $this->notas->criarRascunho(
                (int) $empresa->id,
                $clienteId,
                $tipo,
                $request->validated('motivo'),
                $linhas,
                $faturaId !== null ? (int) $faturaId : null,
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            // Regras de negócio: mensagens seguras para o utilizador.
            return back()->withInput()->withErrors(['nota' => $e->getMessage()]);
        }

        return redirect()->route('faturacao.notas.mostrar', $nota->id)
            ->with('sucesso', 'Rascunho criado. Revê os valores antes de emitir.');
    }

    public function mostrar(int $nota): View
    {
        return view('faturacao::notas.mostrar', [
            'nota' => NotaCreditoDebito::with('linhas', 'cliente', 'fatura', 'agtSubmissao')->findOrFail($nota),
        ]);
    }

    public function emitir(int $nota): RedirectResponse
    {
        $modelo = NotaCreditoDebito::findOrFail($nota);

        $this->garantirQuePodeEmitir($modelo->empresa);

        try {
            $this->notas->emitir($modelo);
        } catch (\DomainException|\InvalidArgumentException|EmissaoNaoPermitidaException $e) {
            // Mensagens de regra de negócio (ex.: crédito acima do faturado): seguras para o utilizador.
            return back()->with('erro', $e->getMessage());
        } catch (AssinaturaFiscalException) {
            return back()->with('erro', 'Não foi possível assinar o documento. A equipa técnica foi notificada.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('erro', 'Ocorreu um erro ao emitir a nota. Tenta novamente.');
        }

        return redirect()->route('faturacao.notas.mostrar', $modelo->id)
            ->with('sucesso', 'Nota emitida com sucesso.');
    }

    public function eliminar(int $nota): RedirectResponse
    {
        $modelo = NotaCreditoDebito::findOrFail($nota);

        try {
            $this->notas->eliminarRascunho($modelo);
        } catch (\DomainException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('faturacao.notas.index')->with('sucesso', 'Rascunho eliminado.');
    }

    protected function garantirQuePodeEmitir(Empresa $empresa): void
    {
        abort_unless(
            $empresa->podeEmitirFaturas(),
            402,
            'A tua empresa está no período de tolerância — podes consultar documentos antigos, mas não emitir novos até regularizares a subscrição.'
        );
    }
}
