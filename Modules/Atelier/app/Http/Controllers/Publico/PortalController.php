<?php

namespace Modules\Atelier\Http\Controllers\Publico;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Medida;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;

/**
 * Requisito E — acesso sem conta/password, via link assinado (ver
 * PortalLinkService). Nada aqui corre atrás do middleware 'auth'/'tenant':
 * o "login" é a própria assinatura da URL, validada pelo middleware nativo
 * 'signed' do Laravel nas rotas (ver routes/web.php).
 *
 * Cada método resolve o Cliente dentro de TenantManager::semTenant() — é
 * a ÚNICA forma de o carregar antes de sabermos a que empresa pertence,
 * já que Cliente usa BelongsToTenant e a TenantScope é fail-closed sem
 * tenant definido. Assim que temos o cliente, chamamos tenantManager->set()
 * para que todas as queries seguintes (Pedidos, Provas, Medidas) fiquem
 * corretamente isoladas pelo resto do pedido — nunca deixamos o bypass
 * "aberto" mais tempo do que essa única resolução inicial.
 *
 * O portal só funciona enquanto a empresa tem acesso ativo e o serviço
 * atelier contratado.
 */
class PortalController extends Controller
{
    /** Estados de uma prova que ainda podem ser reagendados pelo cliente. */
    private const ESTADOS_REAGENDAVEIS = ['agendada', 'confirmada', 'reagendada'];

    public function __construct(
        protected TenantManager $tenantManager,
        protected PortalLinkService $linkService,
    ) {}

    public function mostrar(int $cliente): View
    {
        $clienteModel = $this->resolverCliente($cliente);

        $pedidos = Pedido::where('cliente_id', $clienteModel->id)
            ->with(['provas' => fn ($q) => $q->orderBy('data_hora_agendada')])
            ->latest('id')
            ->get();

        return view('atelier::portal.mostrar', [
            'cliente' => $clienteModel,
            'pedidos' => $pedidos,
            'medidaAtual' => Medida::where('cliente_id', $clienteModel->id)->latest('id')->first(),
            'fluxoEstados' => config('atelier.fluxo_estados'),
            'linkService' => $this->linkService,
        ]);
    }

    public function reagendar(Request $request, int $cliente, int $prova): View|RedirectResponse
    {
        $clienteModel = $this->resolverCliente($cliente);
        $provaModel = $this->resolverProva(provaId: $prova, clienteId: $clienteModel->id);

        if (! $this->provaPodeSerReagendada($provaModel)) {
            return redirect($this->linkService->gerarLinkAcesso($clienteModel))
                ->with('erro', 'Esta prova já não pode ser reagendada por aqui. Fala connosco, por favor.');
        }

        if ($request->isMethod('get')) {
            return view('atelier::portal.reagendar', ['cliente' => $clienteModel, 'prova' => $provaModel]);
        }

        $validado = $request->validate([
            'nova_data_hora' => ['required', 'date', 'after:now'],
        ]);

        $provaModel->update([
            'data_hora_proposta_cliente' => $validado['nova_data_hora'],
            'estado' => 'reagendada',
        ]);

        // O link devolvido nunca expira depois do link com que o cliente
        // chegou aqui (ver PortalLinkService::expiracao).
        return redirect($this->linkService->gerarLinkAcesso($clienteModel))
            ->with('sucesso', 'Pedido de novo horário enviado — vamos confirmar consigo em breve.');
    }

    /**
     * Link "deixar de receber marketing" (Lei n.º 22/11): GET mostra a
     * confirmação (para scanners de links/pré-visualizações não cancelarem
     * por engano), POST regista a revogação do consentimento.
     */
    public function cancelarMarketing(Request $request, int $cliente): View
    {
        $clienteModel = $this->resolverCliente($cliente);
        $concluido = false;

        if ($request->isMethod('post')) {
            $crm = ClienteCrm::firstOrNew(['cliente_id' => $clienteModel->id]);
            $crm->empresa_id = $clienteModel->empresa_id;
            $crm->aceita_marketing = false;
            $crm->consentimento_revogado_em = now();
            $crm->save();

            $concluido = true;
        }

        return view('atelier::portal.marketing-cancelar', [
            'cliente' => $clienteModel,
            'empresa' => Empresa::find($clienteModel->empresa_id),
            'concluido' => $concluido,
        ]);
    }

    protected function resolverCliente(int $clienteId): Cliente
    {
        $cliente = $this->tenantManager->semTenant(fn () => Cliente::findOrFail($clienteId));

        $empresa = Empresa::find($cliente->empresa_id);
        abort_unless($empresa && $empresa->temAcesso() && $empresa->temServico('atelier'), 404);

        $this->tenantManager->set($cliente->empresa_id);

        return $cliente;
    }

    protected function resolverProva(int $provaId, int $clienteId): Prova
    {
        return Prova::where('id', $provaId)
            ->whereHas('pedido', fn ($q) => $q->where('cliente_id', $clienteId))
            ->firstOrFail();
    }

    protected function provaPodeSerReagendada(Prova $prova): bool
    {
        $prova->loadMissing('pedido');

        return in_array($prova->estado, self::ESTADOS_REAGENDAVEIS, true)
            && $prova->data_hora_agendada?->isFuture()
            && ! $prova->pedido?->estaFinalizado();
    }
}
