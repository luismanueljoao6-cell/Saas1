<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Faturacao\Models\Cliente;

/**
 * Único sítio onde o consentimento de marketing de um cliente é registado
 * (com data e origem) ou revogado. Sem consentimento registado aqui — ou
 * dado pelo próprio cliente — nenhuma campanha lhe é enviada (CrmService).
 *
 * {cliente} chega como int (e não como model tipado) pelo mesmo motivo que
 * nos restantes controllers: o binding corre antes do middleware 'tenant'.
 */
class ClienteCrmController extends Controller
{
    public function editar(Request $request, int $cliente): View
    {
        $this->autorizar($request);

        $clienteModel = Cliente::findOrFail($cliente);

        return view('atelier::crm.editar', [
            'cliente' => $clienteModel,
            'crm' => ClienteCrm::where('cliente_id', $clienteModel->id)->first(),
        ]);
    }

    public function atualizar(Request $request, int $cliente): RedirectResponse
    {
        $this->autorizar($request);

        $clienteModel = Cliente::findOrFail($cliente);

        $dados = $request->validate([
            'data_nascimento' => ['nullable', 'date', 'before:today'],
            'aceita_marketing' => ['nullable', 'boolean'],
            'consentimento_origem' => ['nullable', 'required_if:aceita_marketing,1', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $aceita = $request->boolean('aceita_marketing');

        $crm = ClienteCrm::firstOrNew(['cliente_id' => $clienteModel->id]);
        $crm->empresa_id = $clienteModel->empresa_id;
        $crm->data_nascimento = $dados['data_nascimento'] ?? null;
        $crm->notas = $dados['notas'] ?? null;

        if ($aceita) {
            if (! $crm->temConsentimentoValido()) {
                $crm->consentimento_em = now();
            }

            $crm->consentimento_origem = $dados['consentimento_origem'];
            $crm->consentimento_revogado_em = null;
        } elseif ($crm->aceita_marketing) {
            $crm->consentimento_revogado_em = now();
        }

        $crm->aceita_marketing = $aceita;
        $crm->save();

        return back()->with('sucesso', 'Dados de marketing guardados.');
    }

    protected function autorizar(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);
    }
}
