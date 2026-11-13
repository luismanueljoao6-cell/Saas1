<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Http\Requests\GuardarReciboRequest;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Dinheiro;
use Throwable;

/**
 * Recibos de faturas emitidas. Um recibo é emitido logo (não tem rascunho), por isso criar e
 * guardar são ações de Administrador. {recibo} chega como int (ver FaturaController).
 */
class ReciboController extends Controller
{
    public function __construct(protected ReciboService $recibos) {}

    public function index(Request $request): View
    {
        $dados = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $recibos = Recibo::query()
            ->with('cliente', 'fatura', 'agtSubmissao')
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $termo = trim($termo);
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('numero_documento', 'like', "%{$termo}%")
                        ->orWhere('meio_pagamento', 'like', "%{$termo}%")
                        ->orWhereHas('cliente', fn ($cliente) => $cliente->where('nome', 'like', "%{$termo}%"))
                        ->orWhereHas('fatura', fn ($fatura) => $fatura->where('numero_documento', 'like', "%{$termo}%"));
                });
            })
            ->when($dados['estado'] ?? null, fn ($query, string $estado) => $query->where('estado', $estado))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('faturacao::recibos.index', compact('recibos'));
    }

    public function criar(Request $request): View|RedirectResponse
    {
        $fatura = Fatura::with('cliente')->find($request->integer('fatura'));

        if (! $fatura || ! $fatura->estaEmitido()) {
            return redirect()->route('faturacao.faturas.index')->with('erro', 'Escolhe uma fatura emitida para registar o recibo.');
        }

        $resumo = $this->recibos->resumoDaFatura($fatura);

        if ($resumo['saldo'] <= 0) {
            return redirect()->route('faturacao.faturas.mostrar', $fatura->id)->with('erro', 'Esta fatura não tem valor em dívida.');
        }

        return view('faturacao::recibos.criar', [
            'fatura' => $fatura,
            'resumo' => $resumo,
            'saldoSugerido' => Dinheiro::formatar($resumo['saldo']),
            'meiosPagamento' => (array) config('faturacao.meios_pagamento', []),
        ]);
    }

    public function guardar(GuardarReciboRequest $request): RedirectResponse
    {
        $fatura = Fatura::findOrFail($request->validated('fatura_id'));

        $this->garantirQuePodeEmitir($fatura->empresa);

        try {
            $recibo = $this->recibos->emitir(
                (int) $fatura->empresa_id,
                (int) $fatura->cliente_id,
                $request->validated('valor'),
                $request->validated('meio_pagamento'),
                (int) $fatura->id,
            );
        } catch (\DomainException|\InvalidArgumentException|EmissaoNaoPermitidaException $e) {
            // Ex.: valor acima do que está em dívida (já deduzidas as notas de crédito).
            return back()->withInput()->withErrors(['recibo' => $e->getMessage()]);
        } catch (AssinaturaFiscalException) {
            return back()->withInput()->withErrors(['recibo' => 'Não foi possível assinar o documento. A equipa técnica foi notificada.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['recibo' => 'Ocorreu um erro ao emitir o recibo. Tenta novamente.']);
        }

        return redirect()->route('faturacao.recibos.mostrar', $recibo->id)
            ->with('sucesso', "Recibo {$recibo->numero_documento} emitido.");
    }

    public function mostrar(int $recibo): View
    {
        return view('faturacao::recibos.mostrar', [
            'recibo' => Recibo::with('cliente', 'fatura', 'agtSubmissao')->findOrFail($recibo),
        ]);
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
