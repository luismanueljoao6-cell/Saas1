#!/usr/bin/env bash
# =============================================================================
# Atelier — 2.ª varredura (aplicar DEPOIS de aplicar-correcoes-atelier.sh)
#
#     bash aplicar-correcoes-atelier-2.sh
#     php artisan test
#
# O script SOBRESCREVE ficheiros do módulo Atelier (os listados na saída).
# Usa "git diff" para rever e "git checkout -- ." para reverter.
# =============================================================================
set -euo pipefail

if [ ! -f artisan ] || [ ! -d Modules/Atelier ]; then
    echo "ERRO: corre este script na raiz do projeto (onde está o 'artisan')." >&2
    exit 1
fi

if [ ! -f Modules/Atelier/app/Http/Controllers/Admin/FotoController.php ]; then
    echo "ERRO: aplica primeiro o aplicar-correcoes-atelier.sh (a 1.ª ronda de correções)." >&2
    exit 1
fi

escrever() {
    mkdir -p "$(dirname "$1")"
    cat > "$1"
    echo "  ok  $1"
}

echo "A aplicar a 2.ª ronda de correções ao módulo Atelier..."

# -----------------------------------------------------------------------------
# PROVAS: não agendar em pedidos finalizados; nova data tem de ser futura
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Controllers/Admin/ProvaController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Atelier\Http\Requests\AgendarProvaRequest;
use Modules\Atelier\Http\Requests\AtualizarEstadoProvaRequest;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\Prova;

/**
 * IMPORTANTE: {pedido}/{prova} chegam como int, nunca tipados (route model
 * binding implícito). O Laravel resolve o binding ANTES do middleware
 * 'tenant' correr — nessa altura ainda não há empresa definida, e a
 * TenantScope (fail-closed) faria o binding devolver sempre 404.
 */
class ProvaController extends Controller
{
    public function guardar(AgendarProvaRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);

        // Uma prova num pedido entregue/cancelado geraria lembretes sem sentido ao cliente.
        if ($pedido->estaFinalizado()) {
            return back()->with('erro', 'Não é possível agendar provas num pedido entregue ou cancelado.');
        }

        $pedido->provas()->create([
            'empresa_id' => $pedido->empresa_id,
            'tipo' => $request->validated('tipo'),
            'data_hora_agendada' => $request->validated('data_hora_agendada'),
            'notas' => $request->validated('notas'),
        ]);

        return back()->with('sucesso', 'Prova agendada.');
    }

    /**
     * Cobre tanto marcar confirmada/concluída/falta como aceitar a data que
     * o cliente propôs no Portal (requisito E.4) — nesse caso, o pedido
     * inclui nova_data_hora_agendada e reiniciamos o lembrete (uma prova
     * reagendada para uma nova hora precisa de um novo lembrete 24h antes).
     */
    public function atualizarEstado(AtualizarEstadoProvaRequest $request, int $prova): RedirectResponse
    {
        $prova = Prova::findOrFail($prova);

        $dados = ['estado' => $request->validated('estado')];

        if ($request->validated('notas') !== null) {
            $dados['notas'] = $request->validated('notas');
        }

        if ($novaData = $request->validated('nova_data_hora_agendada')) {
            $dados['data_hora_agendada'] = $novaData;
            $dados['data_hora_proposta_cliente'] = null;
            $dados['lembrete_enviado_em'] = null;
        }

        $prova->update($dados);

        return back()->with('sucesso', 'Prova atualizada.');
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Requests/AtualizarEstadoProvaRequest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarEstadoProvaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', 'in:agendada,confirmada,reagendada,concluida,falta'],
            'nova_data_hora_agendada' => ['nullable', 'date', 'after:now'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# MEDIDAS: dados corporais — Costureira só dos clientes dos seus pedidos
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Controllers/Admin/MedidaController.php' <<'__ATELIER_EOF__'
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
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# PERFIL PÚBLICO e PORTFÓLIO
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Controllers/Admin/PerfilController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarPerfilRequest;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Services\SlugEmpresaService;

class PerfilController extends Controller
{
    public function __construct(protected SlugEmpresaService $slugService) {}

    public function editar(): View
    {
        // Gravar o perfil já exige Administrador (GuardarPerfilRequest); o formulário também.
        abort_unless(request()->user()?->hasRole('Administrador'), 403);

        $empresa = request()->user()->empresa;

        return view('atelier::perfil.editar', [
            'empresa' => $empresa,
            'perfil' => Perfil::firstOrNew(['empresa_id' => $empresa->id]),
        ]);
    }

    public function atualizar(GuardarPerfilRequest $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;

        // Empresas registadas depois da migration do Atelier ainda não têm
        // slug (ver SlugEmpresaService) — gera-se aqui, na primeira gravação.
        $this->slugService->garantir($empresa);

        Perfil::updateOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'historia' => $request->validated('historia'),
                'especialidades' => $request->validated('especialidades'),
                'equipa' => $request->validated('equipa'),
                'redes_sociais' => $request->validated('redes_sociais'),
                'publicado' => $request->boolean('publicado'),
            ],
        );

        return back()->with('sucesso', 'Perfil público atualizado.');
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Requests/GuardarPerfilRequest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarPerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'historia' => ['nullable', 'string', 'max:5000'],
            'especialidades' => ['nullable', 'string', 'max:2000'],
            'equipa' => ['nullable', 'array', 'max:20'],
            'equipa.*.nome' => ['required_with:equipa', 'string', 'max:255'],
            'equipa.*.funcao' => ['nullable', 'string', 'max:255'],
            'redes_sociais.instagram' => ['nullable', 'string', 'max:255'],
            'redes_sociais.facebook' => ['nullable', 'string', 'max:255'],
            'redes_sociais.whatsapp' => ['nullable', 'string', 'max:255'],
            'publicado' => ['sometimes', 'boolean'],
        ];
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Controllers/Admin/PortfolioController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarPortfolioItemRequest;
use Modules\Atelier\Models\PortfolioItem;

class PortfolioController extends Controller
{
    public function index(): View
    {
        $empresaId = request()->user()->empresa_id;

        return view('atelier::portfolio.index', [
            'itens' => PortfolioItem::where('empresa_id', $empresaId)->orderBy('categoria')->orderBy('ordem')->get(),
            'categorias' => config('atelier.categorias_portfolio'),
        ]);
    }

    public function guardar(GuardarPortfolioItemRequest $request): RedirectResponse
    {
        // O portfólio é público por desenho (aparece na landing page): disco 'public'.
        $caminho = $request->file('foto')->store('atelier/portfolio', 'public');

        PortfolioItem::create([
            'empresa_id' => $request->user()->empresa_id,
            'categoria' => $request->validated('categoria'),
            'titulo' => $request->validated('titulo'),
            'caminho_foto' => $caminho,
            'descricao' => $request->validated('descricao'),
            'destaque' => $request->boolean('destaque'),
        ]);

        return back()->with('sucesso', 'Item adicionado ao portfólio.');
    }

    /**
     * PortfolioItem não usa BelongsToTenant (é lido sem tenant pela landing
     * page pública — ver o model) — por isso a verificação de posse aqui
     * tem de ser explícita, não pode vir de um global scope.
     */
    public function destruir(PortfolioItem $portfolioItem): RedirectResponse
    {
        abort_unless((int) $portfolioItem->empresa_id === (int) request()->user()->empresa_id, 403);
        abort_unless(request()->user()->hasRole('Administrador'), 403);

        Storage::disk('public')->delete($portfolioItem->caminho_foto);
        $portfolioItem->delete();

        return back()->with('sucesso', 'Item removido do portfólio.');
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Requests/GuardarPortfolioItemRequest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'categoria' => ['required', 'string', Rule::in(array_keys(config('atelier.categorias_portfolio')))],
            'titulo' => ['required', 'string', 'max:255'],
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'destaque' => ['sometimes', 'boolean'],
        ];
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# PEDIDO: validação mais rigorosa (medida do mesmo cliente, limites, datas, fotos)
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Requests/GuardarPedidoRequest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        $prazoInterno = ['nullable', 'date', 'after_or_equal:today'];

        // before_or_equal contra outro campo ausente falha a validação — só se aplica quando há data de entrega.
        if ($this->filled('data_prevista_entrega')) {
            $prazoInterno[] = 'before_or_equal:data_prevista_entrega';
        }

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            // A medida tem de ser do MESMO cliente do pedido (não de outro cliente da empresa).
            'medida_id' => [
                'nullable',
                'integer',
                Rule::exists('atelier_medidas', 'id')
                    ->where('empresa_id', $empresaId)
                    ->where('cliente_id', (int) $this->input('cliente_id')),
            ],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $empresaId)],
            'tipo_servico' => ['required', 'string', Rule::in(array_keys(config('atelier.tipos_servico')))],
            'descricao' => ['required', 'string', 'max:2000'],
            'tecido_cor' => ['nullable', 'string', 'max:255'],
            'aviamentos_necessarios' => ['nullable', 'string', 'max:2000'],
            'data_prevista_entrega' => ['nullable', 'date', 'after_or_equal:today'],
            'prazo_interno' => $prazoInterno,
            'valor_orcamento' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'materiais' => ['nullable', 'array', 'max:50'],
            'materiais.*.descricao' => ['required_with:materiais', 'string', 'max:255'],
            'materiais.*.quantidade' => ['required_with:materiais', 'numeric', 'min:0.01', 'max:100000'],
            'materiais.*.valor_unitario' => ['required_with:materiais', 'numeric', 'min:0', 'max:999999999.99'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# FATURAÇÃO DO PEDIDO: nada de sinal/fatura/pagamento em pedidos cancelados
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Services/PedidoFaturacaoService.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Dinheiro;
use Throwable;

/**
 * A ponte entre o Atelier e o motor fiscal do Faturacao.
 *
 * - O SINAL vira um Recibo com fatura_id nulo (uma proforma não é documento
 *   fiscal AGT, não entra na cadeia de numeração/hash).
 * - A fatura final é sempre criada como RASCUNHO; a emissão fica no ecrã
 *   normal de faturas do módulo Faturacao.
 * - Toda a emissão de Recibos passa pelo ReciboService (caminho único).
 * - Cada operação relê o Pedido com lock: um duplo clique não emite dois
 *   recibos nem gera duas faturas finais.
 * - Um pedido CANCELADO nunca recebe sinal, fatura nem pagamento novos; o
 *   sinal só se regista enquanto o pedido não está entregue/cancelado.
 */
class PedidoFaturacaoService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
        protected FaturaService $faturaService,
        protected TenantManager $tenantManager,
        protected ?ReciboService $reciboService = null,
    ) {}

    /**
     * @throws PedidoException|Throwable
     */
    public function registarSinal(Pedido $pedido, float $valor, string $meioPagamento): Recibo
    {
        return DB::transaction(function () use ($pedido, $valor, $meioPagamento) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->estaFinalizado()) {
                throw new PedidoException('Não é possível registar o sinal de um pedido entregue ou cancelado.');
            }

            if ($pedido->jaTemSinalRegistado()) {
                throw PedidoException::sinalJaRegistado();
            }

            if ($valor <= 0) {
                throw PedidoException::valorInvalido();
            }

            if (Dinheiro::centimos($valor) > Dinheiro::centimos($pedido->valorTotalComIva())) {
                throw new PedidoException('O sinal não pode exceder o valor total do pedido.');
            }

            $recibo = $this->recibos()->emitir(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                $valor,
                $meioPagamento,
                null,
            );

            $pedido->update([
                'valor_sinal' => Dinheiro::formatar(Dinheiro::centimos($valor)),
                'recibo_sinal_id' => $recibo->id,
            ]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $recibo;
        });
    }

    /**
     * Rascunho da fatura final: uma linha para o serviço/peça (taxa guardada
     * no pedido, nunca a "atual" da config) e uma por material cobrado.
     *
     * @throws PedidoException
     */
    public function gerarFaturaFinalRascunho(Pedido $pedido, ?string $observacoes = null): Fatura
    {
        return DB::transaction(function () use ($pedido, $observacoes) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw new PedidoException('Não é possível gerar a fatura final de um pedido cancelado.');
            }

            if ($pedido->jaTemFaturaFinal()) {
                throw PedidoException::faturaFinalJaGerada();
            }

            $cliente = $pedido->cliente;

            if (! $cliente) {
                throw PedidoException::clienteSemDadosFaturacao();
            }

            $linhas = [
                [
                    'descricao' => "{$pedido->tipoServicoRotulo()}: {$pedido->descricao}",
                    'quantidade' => 1,
                    'preco_unitario' => (float) $pedido->valor_orcamento,
                    'taxa_iva' => (float) $pedido->taxa_iva_aplicada,
                ],
            ];

            foreach ($pedido->materiais as $material) {
                $linhas[] = [
                    'descricao' => $material->descricao,
                    'quantidade' => (float) $material->quantidade,
                    'preco_unitario' => (float) $material->valor_unitario,
                    'taxa_iva' => (float) $pedido->taxa_iva_aplicada,
                ];
            }

            $fatura = $this->faturaService->criarRascunho($pedido->empresa, $cliente, $linhas, $observacoes);

            $pedido->update(['fatura_id' => $fatura->id]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $fatura;
        });
    }

    /**
     * Recebimento do saldo = valor_total da fatura JÁ EMITIDA menos o sinal.
     * Usa a fatura como fonte de verdade (evita diferenças de cêntimos).
     *
     * @throws PedidoException|Throwable
     */
    public function registarPagamentoFinal(Pedido $pedido, string $meioPagamento): Recibo
    {
        return DB::transaction(function () use ($pedido, $meioPagamento) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw new PedidoException('Não é possível registar pagamentos num pedido cancelado.');
            }

            if (! $pedido->jaTemFaturaFinal()) {
                throw new PedidoException('É preciso gerar e emitir a fatura final antes de registar o pagamento do saldo.');
            }

            if ($pedido->recibo_saldo_final_id) {
                throw new PedidoException('O pagamento final deste pedido já foi registado.');
            }

            $fatura = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $pedido->empresa_id)
                ->findOrFail($pedido->fatura_id);

            if (! $fatura->estaEmitido()) {
                throw new PedidoException('A fatura final ainda não foi emitida — emite-a no módulo Faturação antes de registar o pagamento.');
            }

            $saldo = Dinheiro::centimos($fatura->valor_total) - Dinheiro::centimos($pedido->valor_sinal);

            if ($saldo <= 0) {
                throw PedidoException::valorInvalido();
            }

            $recibo = $this->recibos()->emitir(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                Dinheiro::formatar($saldo),
                $meioPagamento,
                (int) $fatura->id,
            );

            $pedido->update(['recibo_saldo_final_id' => $recibo->id]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $recibo;
        });
    }

    protected function bloquear(Pedido $pedido): Pedido
    {
        return Pedido::withoutGlobalScopes()
            ->where('empresa_id', $pedido->empresa_id)
            ->lockForUpdate()
            ->findOrFail($pedido->id);
    }

    protected function recibos(): ReciboService
    {
        return $this->reciboService ?? app(ReciboService::class);
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# NOTIFICAÇÕES: números angolanos (+244), timeout, logs sem dados pessoais
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Support/NumeroTelefone.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Support;

/**
 * Normalização de números de telefone para o formato internacional,
 * assumindo Angola (+244) quando o número vem sem indicativo (ex.:
 * "923 456 789" — o formato em que os clientes costumam ser registados).
 * Sem isto, WhatsApp/SMS recebiam o número local tal como escrito e
 * falhavam em silêncio.
 */
class NumeroTelefone
{
    /**
     * @return string|null só dígitos, com indicativo (ex.: 244923456789); null se inválido
     */
    public static function internacional(?string $numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero) ?? '';

        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        } else {
            $digitos = ltrim($digitos, '0');
        }

        if (strlen($digitos) === 9) {
            return '244'.$digitos;
        }

        if (strlen($digitos) >= 10 && strlen($digitos) <= 15) {
            return $digitos;
        }

        return null;
    }

    /** Para logs: nunca registar o número completo. */
    public static function mascarar(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        return strlen($digitos) > 3 ? str_repeat('*', strlen($digitos) - 3).substr($digitos, -3) : '***';
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Services/Notificacoes/NotificadorClienteService.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\NumeroTelefone;
use Modules\Faturacao\Models\Cliente;
use Throwable;

/**
 * Ponto único de disparo de notificações ao cliente (requisito C). Percorre
 * os canais ativos em config('atelier.canais_notificacao') — por omissão só
 * 'mail', que funciona de imediato; 'whatsapp'/'sms' só fazem algo quando
 * as credenciais em .env estiverem preenchidas (ver WhatsAppCanal/SmsCanal).
 * Falha num canal nunca impede os restantes — cada envio é isolado e só
 * regista um warning.
 *
 * Telefones: normalizados para o formato internacional (Angola, +244, por
 * omissão) antes de chegarem aos canais — ver Support\NumeroTelefone.
 */
class NotificadorClienteService
{
    public function notificar(
        Cliente $cliente,
        string $assunto,
        string $mensagem,
        ?string $linkAcao = null,
        ?string $textoAcao = null,
    ): void {
        foreach ((array) config('atelier.canais_notificacao') as $canal) {
            $canal = trim($canal);

            try {
                match ($canal) {
                    'mail' => $this->enviarMail($cliente, $assunto, $mensagem, $linkAcao, $textoAcao),
                    'whatsapp' => $this->enviarViaCanal(app(WhatsAppCanal::class), $cliente, $mensagem),
                    'sms' => $this->enviarViaCanal(app(SmsCanal::class), $cliente, $mensagem),
                    '' => null,
                    default => Log::warning("Atelier: canal de notificação desconhecido \"{$canal}\"."),
                };
            } catch (Throwable $e) {
                Log::warning('Atelier: falha ao notificar cliente por um canal', [
                    'canal' => $canal,
                    'cliente_id' => $cliente->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Variante para as Campanhas (requisito D): ao contrário de notificar(),
     * respeita SÓ o canal escolhido pelo administrador ao criar a campanha
     * (nunca dispara nos outros canais configurados) e NÃO engole a exceção
     * — DispararCampanhaJob precisa de saber se falhou para marcar o envio
     * como 'falhou' em atelier_campanha_envios, em vez de 'enviado'.
     *
     * @throws NotificacaoException
     */
    public function notificarViaCanalUnico(Cliente $cliente, string $canal, string $assunto, string $mensagem): void
    {
        match ($canal) {
            'mail' => $this->enviarMailOuFalhar($cliente, $assunto, $mensagem),
            'whatsapp' => $this->enviarOuFalhar(app(WhatsAppCanal::class), $cliente, $mensagem),
            'sms' => $this->enviarOuFalhar(app(SmsCanal::class), $cliente, $mensagem),
            default => throw NotificacaoException::naoConfigurado($canal),
        };
    }

    protected function enviarMailOuFalhar(Cliente $cliente, string $assunto, string $mensagem): void
    {
        if (! $cliente->email) {
            throw NotificacaoException::contactoEmFalta('mail');
        }

        Notification::route('mail', $cliente->email)
            ->notify(new ClienteAtelierNotification($assunto, $mensagem));
    }

    protected function enviarOuFalhar(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): void
    {
        $destino = NumeroTelefone::internacional($cliente->telefone);

        if ($destino === null) {
            throw NotificacaoException::contactoEmFalta($canal->identificador());
        }

        $canal->enviar($destino, $mensagem);
    }

    protected function enviarMail(Cliente $cliente, string $assunto, string $mensagem, ?string $linkAcao, ?string $textoAcao): void
    {
        if (! $cliente->email) {
            return;
        }

        Notification::route('mail', $cliente->email)
            ->notify(new ClienteAtelierNotification($assunto, $mensagem, $linkAcao, $textoAcao));
    }

    protected function enviarViaCanal(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): void
    {
        $destino = NumeroTelefone::internacional($cliente->telefone);

        if ($destino === null) {
            return;
        }

        try {
            $canal->enviar($destino, $mensagem);
        } catch (NotificacaoException $e) {
            Log::warning('Atelier: falha ao notificar cliente', [
                'canal' => $canal->identificador(),
                'cliente_id' => $cliente->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Services/Notificacoes/WhatsAppCanal.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\NumeroTelefone;

/**
 * IMPORTANTE — À SEMELHANÇA DA ProxyPayGateway (módulo Subscricoes): esta
 * classe foi escrita com base na documentação pública da WhatsApp Business
 * Cloud API, NÃO testada contra uma conta/número reais (sem esses, e sem
 * acesso à rede neste ambiente de desenvolvimento). Confirma antes de
 * produção:
 *   - o endpoint exato da versão da Graph API em uso;
 *   - se a mensagem exige um "template" pré-aprovado pela Meta fora da
 *     janela de 24h de conversa (mensagens de lembrete/notificação
 *     tipicamente exigem template, não texto livre);
 *   - o formato exato do número (com/sem "+", código do país).
 *
 * Os logs nunca incluem o número completo (dado pessoal) e o corpo da
 * resposta do fornecedor é truncado.
 */
class WhatsAppCanal implements CanalNotificacaoInterface
{
    public function identificador(): string
    {
        return 'whatsapp';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $baseUrl = config('atelier.whatsapp.base_url');
        $token = config('atelier.whatsapp.token');
        $numeroId = config('atelier.whatsapp.numero_remetente_id');

        if (! $baseUrl || ! $token || ! $numeroId) {
            throw NotificacaoException::naoConfigurado('whatsapp');
        }

        try {
            // TODO: confirmar o formato exato do payload (texto livre vs.
            // template) contra a documentação atual antes de usar em produção.
            $resposta = Http::withToken($token)
                ->timeout((int) config('atelier.http_timeout_segundos', 15))
                ->post("{$baseUrl}/{$numeroId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $destino,
                    'type' => 'text',
                    'text' => ['body' => $mensagem],
                ]);
        } catch (ConnectionException $e) {
            Log::error('Falha de ligação ao enviar WhatsApp', ['destino' => NumeroTelefone::mascarar($destino)]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', 'Sem ligação ao fornecedor (tempo esgotado ou rede indisponível).');
        }

        if ($resposta->failed()) {
            $corpo = Str::limit($resposta->body(), 300);

            Log::error('Falha ao enviar WhatsApp', ['destino' => NumeroTelefone::mascarar($destino), 'resposta' => $corpo]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', $corpo);
        }
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Services/Notificacoes/SmsCanal.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\NumeroTelefone;

/**
 * Genérico de propósito: o requisito não especifica qual gateway de SMS
 * angolano usar, ao contrário do pagamento (onde o README do Subscricoes
 * já tinha escolhido ProxyPay/Multicaixa). Assume um endpoint REST simples
 * (destino + mensagem + api_key) — troca o corpo do pedido pelo formato
 * exato do fornecedor escolhido antes de usar. Ver aviso equivalente em
 * WhatsAppCanal (incluindo o cuidado de não registar números completos).
 */
class SmsCanal implements CanalNotificacaoInterface
{
    public function identificador(): string
    {
        return 'sms';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $baseUrl = config('atelier.sms.base_url');
        $apiKey = config('atelier.sms.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw NotificacaoException::naoConfigurado('sms');
        }

        try {
            $resposta = Http::withToken($apiKey)
                ->timeout((int) config('atelier.http_timeout_segundos', 15))
                ->post($baseUrl, [
                    'remetente' => config('atelier.sms.remetente'),
                    'destino' => $destino,
                    'mensagem' => $mensagem,
                ]);
        } catch (ConnectionException $e) {
            Log::error('Falha de ligação ao enviar SMS', ['destino' => NumeroTelefone::mascarar($destino)]);

            throw NotificacaoException::falhaNoEnvio('sms', 'Sem ligação ao fornecedor (tempo esgotado ou rede indisponível).');
        }

        if ($resposta->failed()) {
            $corpo = Str::limit($resposta->body(), 300);

            Log::error('Falha ao enviar SMS', ['destino' => NumeroTelefone::mascarar($destino), 'resposta' => $corpo]);

            throw NotificacaoException::falhaNoEnvio('sms', $corpo);
        }
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Listeners/EnviarNotificacaoMudancaEstado.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Services\TenantManager;

/**
 * Traduz uma mudança de estado (requisito A.2) numa notificação ao cliente
 * (requisito C.1). 'pendente', 'em_corte' e 'em_costura' são passos
 * internos de produção — não geram notificação.
 *
 * Corre na fila, onde não há tenant definido: por isso o acesso a Cliente
 * (que usa TenantScope, fail-closed) é feito dentro de semTenant(), tal como
 * os Jobs do módulo. $afterCommit garante que o worker só corre depois de o
 * novo estado estar gravado.
 *
 * O motivo do cancelamento é uma nota INTERNA (pode conter observações que
 * o cliente não deve ler) — nunca é enviado na mensagem ao cliente.
 */
class EnviarNotificacaoMudancaEstado implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        protected NotificadorClienteService $notificador,
        protected TenantManager $tenantManager,
    ) {}

    public function handle(PedidoMudouEstado $event): void
    {
        $this->tenantManager->semTenant(function () use ($event) {
            $pedido = $event->pedido;
            $cliente = $pedido->cliente;

            if (! $cliente) {
                return;
            }

            $mensagem = $this->mensagemPara($pedido);

            if ($mensagem === null) {
                return;
            }

            $this->notificador->notificar(
                $cliente,
                "Atualização do seu pedido — {$pedido->empresa->nome_comercial}",
                $mensagem,
            );
        });
    }

    protected function mensagemPara(Pedido $pedido): ?string
    {
        return match ($pedido->status) {
            'primeira_prova' => 'A sua peça está pronta para a 1ª prova! Entraremos em contacto para agendar.',
            'ajustes' => 'A sua peça está em fase de ajustes finais.',
            'pronto_para_retirada' => $this->mensagemProntoParaRetirada($pedido),
            'entregue' => 'A sua peça foi entregue. Obrigado por confiar no nosso atelier!',
            'cancelado' => 'O seu pedido foi cancelado. Contacte-nos se tiver alguma dúvida.',
            default => null,
        };
    }

    protected function mensagemProntoParaRetirada(Pedido $pedido): string
    {
        $saldo = $pedido->saldoEmFalta();

        if ($saldo > 0) {
            $valorFormatado = number_format($saldo, 2, ',', '.');

            return "A sua peça está pronta para retirada! Saldo pendente: {$valorFormatado} AOA.";
        }

        return 'A sua peça está pronta para retirada! Não há saldo pendente.';
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Jobs/LembretesProvaJob.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()).
 * Percorre provas de TODAS as empresas — por isso corre dentro do bypass
 * explícito do TenantManager, tal como VerificarSubscricoesExpiradasJob já
 * faz no módulo Subscricoes.
 *
 * Não envia lembretes de provas de pedidos já entregues/cancelados, nem de
 * empresas sem acesso ativo ou sem o serviço atelier.
 */
class LembretesProvaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($notificador) {
            Prova::query()
                ->whereIn('estado', ['agendada', 'confirmada', 'reagendada'])
                ->whereNull('lembrete_enviado_em')
                ->where('data_hora_agendada', '>=', now())
                ->with(['pedido.cliente', 'pedido.empresa'])
                ->chunkById(100, function ($provas) use ($notificador) {
                    foreach ($provas as $prova) {
                        $pedido = $prova->pedido;
                        $empresa = $pedido?->empresa;

                        if (! $pedido || $pedido->estaFinalizado()) {
                            continue;
                        }

                        if (! $empresa || ! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
                            continue;
                        }

                        if (! $prova->precisaDeLembrete()) {
                            continue;
                        }

                        try {
                            $this->enviarLembrete($prova, $notificador);
                            $prova->update(['lembrete_enviado_em' => now()]);
                        } catch (Throwable $e) {
                            Log::error('Atelier: falha ao enviar lembrete de prova', [
                                'prova_id' => $prova->id,
                                'erro' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        });
    }

    protected function enviarLembrete(Prova $prova, NotificadorClienteService $notificador): void
    {
        $cliente = $prova->pedido?->cliente;

        if (! $cliente) {
            return;
        }

        $notificador->notificar(
            $cliente,
            "Lembrete: {$prova->tipoRotulo()} — {$prova->pedido->empresa->nome_comercial}",
            "Lembramos que tem a {$prova->tipoRotulo()} da sua peça agendada para ".
                $prova->data_hora_agendada->format('d/m/Y \à\s H:i').'.',
        );
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# DIREITOS DO TITULAR (Lei n.º 22/11): exportar e eliminar dados de um cliente
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Console/ExportarDadosClienteCommand.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Direito de acesso (Lei n.º 22/11): reúne, em JSON, os dados que o módulo
 * Atelier guarda sobre um cliente. Consulta direta por empresa+cliente
 * (corre na linha de comandos, sem tenant) — não altera nada.
 *
 *   php artisan atelier:exportar-dados-cliente {empresa_id} {cliente_id} > cliente.json
 */
class ExportarDadosClienteCommand extends Command
{
    protected $signature = 'atelier:exportar-dados-cliente {empresa : ID da empresa} {cliente : ID do cliente}';

    protected $description = 'Exporta em JSON os dados do Atelier sobre um cliente (direito de acesso).';

    public function handle(): int
    {
        $empresaId = (int) $this->argument('empresa');
        $clienteId = (int) $this->argument('cliente');

        $cliente = DB::table('clientes')->where('empresa_id', $empresaId)->where('id', $clienteId)->first();

        if (! $cliente) {
            $this->error('Cliente não encontrado nesta empresa.');

            return self::FAILURE;
        }

        $pedidos = DB::table('atelier_pedidos')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->get();

        $pedidoIds = $pedidos->pluck('id');

        $dados = [
            'exportado_em' => now()->toIso8601String(),
            'cliente' => $cliente,
            'crm' => DB::table('atelier_clientes_crm')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->get(),
            'medidas' => DB::table('atelier_medidas')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->get(),
            'pedidos' => $pedidos,
            'fotos' => DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->get(),
            'provas' => DB::table('atelier_provas')->whereIn('pedido_id', $pedidoIds)->get(),
            'envios_de_campanhas' => DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->get(),
        ];

        $this->line(json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Console/EliminarDadosClienteCommand.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Direito de eliminação (Lei n.º 22/11) dos dados que SÓ o módulo Atelier
 * guarda sobre um cliente: medidas corporais, ficha de CRM/consentimento,
 * fotos dos pedidos e registos de envio de campanhas.
 *
 * NÃO apaga o cliente, os pedidos, faturas nem recibos: os documentos
 * fiscais têm de ser conservados durante o prazo legal. Só depois de
 * esse prazo (e com validação do contabilista) se deve anonimizar o
 * cliente no módulo Faturação.
 *
 *   php artisan atelier:eliminar-dados-cliente {empresa_id} {cliente_id} [--force]
 */
class EliminarDadosClienteCommand extends Command
{
    protected $signature = 'atelier:eliminar-dados-cliente {empresa : ID da empresa} {cliente : ID do cliente} {--force : Não pede confirmação}';

    protected $description = 'Elimina os dados pessoais guardados só pelo Atelier sobre um cliente (mantém documentos fiscais).';

    public function handle(): int
    {
        $empresaId = (int) $this->argument('empresa');
        $clienteId = (int) $this->argument('cliente');

        $cliente = DB::table('clientes')->where('empresa_id', $empresaId)->where('id', $clienteId)->first();

        if (! $cliente) {
            $this->error('Cliente não encontrado nesta empresa.');

            return self::FAILURE;
        }

        $this->warn('Vão ser eliminados, de forma irreversível: medidas, ficha de CRM/consentimento, fotos dos pedidos e registos de envio de campanhas.');
        $this->line('Ficam intactos o cliente, os pedidos, as faturas e os recibos (retenção fiscal).');

        if (! $this->option('force') && ! $this->confirm('Continuar?')) {
            $this->info('Nada foi alterado.');

            return self::FAILURE;
        }

        $pedidoIds = DB::table('atelier_pedidos')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->pluck('id');

        $caminhosFotos = DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->pluck('caminho');

        DB::transaction(function () use ($empresaId, $clienteId, $pedidoIds) {
            DB::table('atelier_pedidos')->whereIn('id', $pedidoIds)->update(['medida_id' => null]);
            DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->delete();
            DB::table('atelier_medidas')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->delete();
            DB::table('atelier_clientes_crm')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->delete();
            DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->delete();
        });

        foreach ($caminhosFotos as $caminho) {
            Storage::disk('local')->delete($caminho);
            Storage::disk('public')->delete($caminho);
        }

        $this->info('Dados eliminados.');

        return self::SUCCESS;
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Providers/AtelierServiceProvider.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Atelier\Console\EliminarDadosClienteCommand;
use Modules\Atelier\Console\ExportarDadosClienteCommand;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Jobs\EnviarCampanhasAgendadasJob;
use Modules\Atelier\Jobs\LembretesProvaJob;
use Modules\Atelier\Jobs\VerificarAniversariosJob;
use Modules\Atelier\Listeners\EnviarNotificacaoMudancaEstado;
use Modules\Core\Services\MenuRegistry;

class AtelierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'atelier');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'atelier');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->app->register(RouteServiceProvider::class);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('atelier.php'),
        ], 'atelier-config');

        $this->registarMenu();
        $this->registarListeners();
        $this->registarComandos();
        $this->agendarTarefas();
    }

    protected function registarMenu(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->adicionar('atelier.pedidos.index', 'Pedidos (Atelier)', 60);
        $menu->adicionar('atelier.equipa.index', 'Equipa do Atelier', 65);
        $menu->adicionar('atelier.perfil.editar', 'Perfil Público', 70);
        $menu->adicionar('atelier.orcamentos.index', 'Pedidos de Orçamento', 74);
        $menu->adicionar('atelier.campanhas.index', 'Campanhas', 78);
    }

    protected function registarListeners(): void
    {
        Event::listen(PedidoMudouEstado::class, EnviarNotificacaoMudancaEstado::class);
    }

    /** Direitos do titular dos dados (acesso e eliminação) — ver os comandos. */
    protected function registarComandos(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExportarDadosClienteCommand::class,
                EliminarDadosClienteCommand::class,
            ]);
        }
    }

    /**
     * Mesmo padrão de SubscricoesServiceProvider::agendarVerificacaoDiaria()
     * — registado dentro de $this->app->booted() porque o Schedule só fica
     * disponível depois de a aplicação arrancar por completo, e requer
     * `php artisan schedule:run` no cron do servidor.
     */
    protected function agendarTarefas(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->job(new LembretesProvaJob)
                ->hourly()
                ->name('atelier:lembretes-prova')
                ->withoutOverlapping();

            $schedule->job(new VerificarAniversariosJob)
                ->dailyAt('08:00')
                ->name('atelier:verificar-aniversarios')
                ->withoutOverlapping();

            $schedule->job(new EnviarCampanhasAgendadasJob)
                ->hourly()
                ->name('atelier:enviar-campanhas-agendadas')
                ->withoutOverlapping();
        });
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# TESTE EXISTENTE QUE A 1.ª RONDA PARTIU (formulário público agora exige consentimento)
# -----------------------------------------------------------------------------
echo "A ajustar o teste do Core que usa o formulário público..."
TESTE_CORE='Modules/Core/tests/Feature/ServicosPublicosTest.php'
perl -0pi -e 's/\$dados = \[\x27nome\x27 => \x27Visitante\x27, \x27contacto\x27 => \x27900000000\x27\];/\$dados = [\x27nome\x27 => \x27Visitante\x27, \x27contacto\x27 => \x27900000000\x27, \x27consentimento\x27 => \x271\x27];/' "$TESTE_CORE"
grep -q "'consentimento' => '1'" "$TESTE_CORE" && echo "  ok  $TESTE_CORE" || echo "  AVISO: não consegui ajustar $TESTE_CORE (no teste test_remover_o_servico_fecha_a_landing_do_atelier acrescenta 'consentimento' => '1' ao array \$dados)"

# -----------------------------------------------------------------------------
# TESTES NOVOS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/tests/Feature/CorrecoesAuditoria2Test.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Jobs\LembretesProvaJob;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Atelier\Support\NumeroTelefone;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * 2.ª varredura ao módulo Atelier: pedidos cancelados não faturam nem
 * geram lembretes, telefones angolanos, e direitos do titular dos dados.
 */
class CorrecoesAuditoria2Test extends TestCase
{
    use RefreshDatabase;

    protected function criarPedido(string $nif, array $extra = []): Pedido
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos(['atelier']);
        app(TenantManager::class)->set($empresa->id);

        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create(array_merge([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de teste',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
        ], $extra));
    }

    public function test_pedido_cancelado_nao_aceita_sinal(): void
    {
        $pedido = $this->criarPedido('400000301', ['status' => 'cancelado']);

        $this->expectException(PedidoException::class);

        app(PedidoFaturacaoService::class)->registarSinal($pedido, 100, 'dinheiro');
    }

    public function test_pedido_cancelado_nao_gera_fatura_final(): void
    {
        $pedido = $this->criarPedido('400000302', ['status' => 'cancelado']);

        $this->expectException(PedidoException::class);

        app(PedidoFaturacaoService::class)->gerarFaturaFinalRascunho($pedido);
    }

    public function test_lembretes_de_prova_ignoram_pedidos_cancelados(): void
    {
        $pedido = $this->criarPedido('400000303', ['status' => 'cancelado']);

        $prova = $pedido->provas()->create([
            'empresa_id' => $pedido->empresa_id,
            'tipo' => 'primeira',
            'data_hora_agendada' => now()->addHours(2),
        ]);

        (new LembretesProvaJob)->handle(app(TenantManager::class), app(NotificadorClienteService::class));

        $this->assertNull(Prova::withoutGlobalScopes()->find($prova->id)->lembrete_enviado_em);
    }

    public function test_numeros_angolanos_sao_normalizados_para_o_formato_internacional(): void
    {
        $this->assertSame('244923456789', NumeroTelefone::internacional('923 456 789'));
        $this->assertSame('244923456789', NumeroTelefone::internacional('+244 923 456 789'));
        $this->assertSame('244923456789', NumeroTelefone::internacional('00244923456789'));
        $this->assertNull(NumeroTelefone::internacional('12345'));
        $this->assertNull(NumeroTelefone::internacional(null));
        $this->assertStringEndsWith('789', NumeroTelefone::mascarar('244923456789'));
        $this->assertStringNotContainsString('923', NumeroTelefone::mascarar('244923456789'));
    }

    public function test_comando_elimina_dados_do_atelier_mas_mantem_cliente_e_pedido(): void
    {
        $pedido = $this->criarPedido('400000304');

        ClienteCrm::create([
            'cliente_id' => $pedido->cliente_id,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
        ]);
        $pedido->fotos()->create(['caminho' => 'atelier/pedidos/inexistente.jpg']);

        $this->artisan('atelier:eliminar-dados-cliente', [
            'empresa' => $pedido->empresa_id,
            'cliente' => $pedido->cliente_id,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, ClienteCrm::withoutGlobalScopes()->where('cliente_id', $pedido->cliente_id)->count());
        $this->assertSame(0, $pedido->fotos()->count());
        $this->assertNotNull(Pedido::withoutGlobalScopes()->find($pedido->id));
        $this->assertNotNull(Cliente::withoutGlobalScopes()->find($pedido->cliente_id));
    }

    public function test_comando_exporta_os_dados_do_cliente(): void
    {
        $pedido = $this->criarPedido('400000305');

        $this->artisan('atelier:exportar-dados-cliente', [
            'empresa' => $pedido->empresa_id,
            'cliente' => $pedido->cliente_id,
        ])->assertExitCode(0);
    }

    public function test_comandos_recusam_cliente_de_outra_empresa(): void
    {
        $pedido = $this->criarPedido('400000306');

        $this->artisan('atelier:eliminar-dados-cliente', [
            'empresa' => $pedido->empresa_id + 999,
            'cliente' => $pedido->cliente_id,
            '--force' => true,
        ])->assertExitCode(1);
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# README
# -----------------------------------------------------------------------------
cat >> Modules/Atelier/README.md <<'__ATELIER_EOF__'

### 2.ª varredura

- **Provas**: não se agendam em pedidos entregues/cancelados; a nova data tem de ser futura; os lembretes ignoram pedidos finalizados e empresas sem acesso/serviço.
- **Medidas corporais**: a Costureira só vê o histórico dos clientes dos pedidos que lhe estão atribuídos; o formulário de novas medidas é só para Administrador/Secretária.
- **Pedidos**: a medida tem de ser do mesmo cliente; limites de valores, datas e fotos (máx. 10, jpg/png/webp); pedidos cancelados não recebem sinal, fatura final nem pagamentos.
- **Notificações**: números angolanos normalizados para +244; timeout nas chamadas HTTP; logs sem o número completo; a mensagem de cancelamento ao cliente já não inclui o motivo interno.
- **Direitos do titular (Lei n.º 22/11)**: `php artisan atelier:exportar-dados-cliente {empresa} {cliente}` e `php artisan atelier:eliminar-dados-cliente {empresa} {cliente} [--force]` (mantêm-se pedidos, faturas e recibos por retenção fiscal).
__ATELIER_EOF__
echo "  ok  Modules/Atelier/README.md (secção adicionada)"

echo
echo "Concluído. Próximos passos:"
echo "  1) git diff"
echo "  2) php artisan test"
echo "  3) Se EstadosSessaoTest (EstudioMusica) continuar a falhar, confirma se também falha sem estas alterações:"
echo "       git stash -u && php artisan test --filter=EstadosSessaoTest; git stash pop"
