<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarMedidaRequest;
use Modules\Atelier\Models\Medida;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\MedidaService;
use Modules\Faturacao\Models\Cliente;

/**
 * IMPORTANTE: {cliente} chega como int, nunca como Cliente tipado (route
 * model binding implícito). O Laravel resolve o binding ANTES do
 * middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver sempre
 * 404. Vale também para Cliente vindo doutro módulo (Faturacao), não só
 * para os models do próprio Atelier.
 *
 * Medidas corporais são dados pessoais: Administrador e Secretária veem
 * todas; a Costureira só as dos clientes cujos pedidos lhe estão atribuídos.
 */
class MedidaController extends Controller
{
    public function __construct(protected MedidaService $medidaService) {}

    public function historico(Request $request, int $cliente): View
    {
        $cliente = Cliente::findOrFail($cliente);
        $this->garantirAcessoAoCliente($request, $cliente);

        return view('atelier::medidas.historico', [
            'cliente' => $cliente,
            'medidas' => $this->medidaService->historicoPara($cliente),
            'camposMedida' => Medida::camposMedida(),
        ]);
    }

    public function criar(Request $request, int $cliente): View
    {
        // Só quem pode gravar medidas (ver GuardarMedidaRequest) vê o formulário.
        abort_unless($request->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);

        $cliente = Cliente::findOrFail($cliente);

        return view('atelier::medidas.criar', [
            'cliente' => $cliente,
            'ultimaMedida' => $this->medidaService->maisRecentePara($cliente),
            'camposMedida' => Medida::camposMedida(),
        ]);
    }

    public function guardar(GuardarMedidaRequest $request): RedirectResponse
    {
        $cliente = Cliente::findOrFail($request->validated('cliente_id'));

        $this->medidaService->registar(
            $request->user()->empresa,
            $cliente,
            $request->validated(),
            $request->validated('observacoes'),
            $request->user(),
        );

        return redirect()->route('atelier.medidas.historico', $cliente)
            ->with('sucesso', 'Novas medidas registadas.');
    }

    protected function garantirAcessoAoCliente(Request $request, Cliente $cliente): void
    {
        $utilizador = $request->user();

        if ($utilizador?->hasAnyRole(['Administrador', 'Secretária'])) {
            return;
        }

        $temPedidoAtribuido = Pedido::where('cliente_id', $cliente->id)
            ->where('responsavel_id', $utilizador?->id)
            ->exists();

        abort_unless($temPedidoAtribuido, 403);
    }
}
