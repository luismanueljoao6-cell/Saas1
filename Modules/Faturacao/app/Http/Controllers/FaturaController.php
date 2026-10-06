<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Http\Requests\GuardarFaturaRequest;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Services\FaturaService;
use Throwable;

/**
 * IMPORTANTE: {fatura} chega como int, nunca como Fatura tipado (route
 * model binding implícito). O Laravel resolve o binding ANTES do
 * middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver sempre
 * 404. O findOrFail() dentro de cada método já corre com o tenant certo.
 */
class FaturaController extends Controller
{
    public function __construct(protected FaturaService $faturaService)
    {
    }

    public function index(): View
    {
        return view('faturacao::faturas.index', [
            'faturas' => Fatura::with('cliente')->latest('id')->paginate(20),
        ]);
    }

    public function criar(): View
    {
        return view('faturacao::faturas.criar', [
            'clientes' => Cliente::orderBy('nome')->get(),
            'produtos' => Produto::ativos()->orderBy('nome')->get(),
            'taxaIvaGeral' => (float) config('faturacao.taxa_iva_geral'),
        ]);
    }

    public function guardar(GuardarFaturaRequest $request): RedirectResponse
    {
        $this->garantirQuePodeEmitirFaturas($request->user()->empresa);

        $cliente = Cliente::findOrFail($request->validated('cliente_id'));

        $fatura = $this->faturaService->criarRascunho(
            $request->user()->empresa,
            $cliente,
            $request->validated('linhas'),
            $request->validated('observacoes'),
        );

        return redirect()->route('faturacao.faturas.mostrar', $fatura)
            ->with('sucesso', 'Rascunho criado. Revê os valores antes de emitir.');
    }

    public function mostrar(int $fatura): View
    {
        return view('faturacao::faturas.mostrar', [
            'fatura' => Fatura::with('linhas', 'cliente')->findOrFail($fatura),
        ]);
    }

    public function emitir(int $fatura): RedirectResponse
    {
        $modelo = Fatura::findOrFail($fatura);

        $this->garantirQuePodeEmitirFaturas($modelo->empresa);

        try {
            $this->faturaService->emitir($modelo);
        } catch (AssinaturaFiscalException $e) {
            Log::error('Não foi possível emitir a fatura', ['fatura_id' => $modelo->id, 'erro' => $e->getMessage()]);

            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha inesperada ao emitir fatura', ['fatura_id' => $modelo->id, 'erro' => $e->getMessage()]);

            return back()->with('erro', 'Ocorreu um erro ao emitir a fatura. Tenta novamente.');
        }

        return redirect()->route('faturacao.faturas.mostrar', $modelo)
            ->with('sucesso', 'Fatura emitida com sucesso.');
    }

    /**
     * Aplica exatamente a distinção que o Core deixou pronta para este
     * módulo: durante o grace period, Empresa::temAcesso() é true (o
     * middleware 'subscricao.ativa' deixa passar), mas
     * Empresa::podeEmitirFaturas() é false — é aqui, no ponto exato de
     * criar/emitir, que essa segunda verificação tem de acontecer.
     */
    protected function garantirQuePodeEmitirFaturas(\Modules\Core\Models\Empresa $empresa): void
    {
        abort_unless(
            $empresa->podeEmitirFaturas(),
            402,
            'A tua empresa está no período de tolerância — podes consultar documentos antigos, mas não emitir novos até regularizares a subscrição.'
        );
    }
}
