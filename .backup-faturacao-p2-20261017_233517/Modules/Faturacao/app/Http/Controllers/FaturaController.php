<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Http\Requests\GuardarFaturaRequest;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Services\FaturaService;
use Throwable;

/**
 * {fatura} chega como int (sem route model binding): o binding corre antes
 * do middleware 'tenant' e a TenantScope fail-closed devolveria sempre 404.
 */
class FaturaController extends Controller
{
    public function __construct(protected FaturaService $faturaService) {}

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
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403, 'Utilizador sem empresa associada.');

        $this->garantirQuePodeEmitirFaturas($empresa);

        $cliente = Cliente::findOrFail($request->validated('cliente_id'));

        $fatura = $this->faturaService->criarRascunho(
            $empresa,
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
