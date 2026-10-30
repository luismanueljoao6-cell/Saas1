#!/usr/bin/env bash
# =============================================================================
# Correções do módulo Atelier (auditoria técnica + conformidade Angola)
#
# Como usar (na raiz do projeto Laravel, onde está o ficheiro "artisan"):
#     git checkout -b correcoes-atelier      # opcional, mas recomendado
#     bash aplicar-correcoes-atelier.sh
#     php artisan migrate
#     php artisan test --filter=Atelier      # ou: php artisan test Modules/Atelier
#
# O script SOBRESCREVE ficheiros do módulo Atelier (os que estão listados na
# saída). Usa o git para rever (git diff) e reverter (git checkout -- .).
# =============================================================================
set -euo pipefail

if [ ! -f artisan ] || [ ! -d Modules/Atelier ]; then
    echo "ERRO: corre este script na raiz do projeto (onde está o 'artisan' e a pasta Modules/Atelier)." >&2
    exit 1
fi

escrever() {
    mkdir -p "$(dirname "$1")"
    cat > "$1"
    echo "  ok  $1"
}

echo "A aplicar correções ao módulo Atelier..."

# -----------------------------------------------------------------------------
# MIGRAÇÕES
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/database/migrations/2026_10_14_000001_atelier_consentimento_e_retencao.php' <<'__ATELIER_EOF__'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conformidade (Lei n.º 22/11 — Protecção de Dados Pessoais) e retenção:
 *
 * - Marketing passa a exigir consentimento EXPLÍCITO e provado: o default de
 *   aceita_marketing passa a false e guardam-se data, origem e revogação.
 *   As linhas antigas (que herdaram o default true sem prova de consentimento)
 *   ficam com consentimento_em nulo e, por isso, deixam de receber marketing
 *   até o consentimento ser registado (ver CrmService).
 * - O formulário público de orçamento passa a guardar quando o visitante
 *   aceitou o tratamento dos dados.
 * - Apagar uma empresa deixa de apagar em cascata os pedidos (ligados a
 *   documentos fiscais, que têm de ser conservados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            $table->boolean('aceita_marketing')->default(false)->change();
            $table->timestamp('consentimento_em')->nullable();
            $table->string('consentimento_origem')->nullable();
            $table->timestamp('consentimento_revogado_em')->nullable();
        });

        Schema::table('atelier_pedidos_orcamento', function (Blueprint $table) {
            $table->timestamp('consentimento_em')->nullable();
        });

        Schema::table('atelier_pedidos', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atelier_pedidos', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->foreign('empresa_id')->references('id')->on('empresas')->cascadeOnDelete();
        });

        Schema::table('atelier_pedidos_orcamento', function (Blueprint $table) {
            $table->dropColumn('consentimento_em');
        });

        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            $table->dropColumn(['consentimento_em', 'consentimento_origem', 'consentimento_revogado_em']);
            $table->boolean('aceita_marketing')->default(true)->change();
        });
    }
};
__ATELIER_EOF__

escrever 'Modules/Atelier/database/migrations/2026_10_14_000002_atelier_mover_fotos_para_disco_privado.php' <<'__ATELIER_EOF__'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * As fotos dos pedidos são dados pessoais dos clientes: deixam de ficar no
 * disco 'public' (acessível por URL sem login) e passam para o disco
 * privado 'local', servido só por rota autenticada (FotoController).
 *
 * Move os ficheiros já existentes; uma falha num ficheiro é registada e não
 * impede os restantes (FotoController continua a servir do disco antigo
 * enquanto o ficheiro lá estiver).
 */
return new class extends Migration
{
    public function up(): void
    {
        $publico = Storage::disk('public');
        $privado = Storage::disk('local');

        DB::table('atelier_pedido_fotos')->orderBy('id')->each(function ($foto) use ($publico, $privado) {
            $caminho = $foto->caminho;

            try {
                if (! $publico->exists($caminho) || $privado->exists($caminho)) {
                    return;
                }

                $stream = $publico->readStream($caminho);
                $privado->writeStream($caminho, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($privado->exists($caminho)) {
                    $publico->delete($caminho);
                }
            } catch (Throwable $e) {
                Log::warning('Atelier: não foi possível mover uma foto para o disco privado', [
                    'foto_id' => $foto->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio: não voltamos a expor fotos de clientes em público.
    }
};
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# CONTROLLERS NOVOS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Controllers/Admin/FotoController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoFoto;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve as fotos dos pedidos (dados pessoais) só a utilizadores
 * autenticados da própria empresa. PedidoFoto não tem tenant próprio, por
 * isso o isolamento vem do Pedido (TenantScope fail-closed).
 */
class FotoController extends Controller
{
    public function mostrar(Request $request, int $foto): StreamedResponse
    {
        $foto = PedidoFoto::findOrFail($foto);
        $pedido = Pedido::findOrFail($foto->pedido_id);
        $utilizador = $request->user();

        abort_unless(
            $utilizador->hasAnyRole(['Administrador', 'Secretária'])
                || ($utilizador->hasRole('Costureira') && (int) $pedido->responsavel_id === (int) $utilizador->id),
            403
        );

        $disco = Storage::disk(PedidoFoto::DISCO)->exists($foto->caminho)
            ? PedidoFoto::DISCO
            : PedidoFoto::DISCO_LEGADO;

        abort_unless(Storage::disk($disco)->exists($foto->caminho), 404);

        return Storage::disk($disco)->response($foto->caminho, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Controllers/Admin/ClienteCrmController.php' <<'__ATELIER_EOF__'
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
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# VIEWS NOVAS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/resources/views/crm/editar.blade.php' <<'__ATELIER_EOF__'
@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Marketing — ' . $cliente->nome)

@section('conteudo')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Marketing e consentimento — {{ $cliente->nome }}</h1>
        <p class="text-sm text-slate-500">
            Só enviamos campanhas e mensagens de aniversário a clientes que deram consentimento.
            Regista aqui como e quando o cliente o deu.
        </p>
    </div>

    @if (session('sucesso'))
        <div class="mb-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $erro)
                <p>{{ $erro }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('atelier.crm.atualizar', $cliente->id) }}"
          class="max-w-lg space-y-4 rounded-md border border-slate-200 bg-white p-5">
        @csrf
        @method('PUT')

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Data de nascimento</label>
            <input type="date" name="data_nascimento" max="{{ now()->subDay()->format('Y-m-d') }}"
                   value="{{ old('data_nascimento', $crm?->data_nascimento?->format('Y-m-d')) }}"
                   class="w-full rounded-md border-slate-300 text-sm">
        </div>

        <div class="space-y-2">
            <input type="hidden" name="aceita_marketing" value="0">
            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" name="aceita_marketing" value="1" class="mt-0.5 rounded border-slate-300"
                       @checked((string) old('aceita_marketing', $crm?->temConsentimentoValido() ? '1' : '0') === '1')>
                <span>O cliente consente em receber comunicações de marketing (campanhas e mensagens de aniversário).</span>
            </label>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Como foi obtido o consentimento? *</label>
                <input type="text" name="consentimento_origem" maxlength="255"
                       placeholder="Ex.: ficha em papel assinada, mensagem de WhatsApp, presencialmente"
                       value="{{ old('consentimento_origem', $crm?->consentimento_origem) }}"
                       class="w-full rounded-md border-slate-300 text-sm">
            </div>

            @if ($crm?->consentimento_em)
                <p class="text-xs text-slate-400">Consentimento registado em {{ $crm->consentimento_em->format('d/m/Y H:i') }}.</p>
            @endif
            @if ($crm?->consentimento_revogado_em)
                <p class="text-xs text-amber-600">Consentimento revogado em {{ $crm->consentimento_revogado_em->format('d/m/Y H:i') }}.</p>
            @endif
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Notas</label>
            <textarea name="notas" rows="3" maxlength="2000" class="w-full rounded-md border-slate-300 text-sm">{{ old('notas', $crm?->notas) }}</textarea>
        </div>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Guardar
        </button>
    </form>
@endsection
__ATELIER_EOF__

escrever 'Modules/Atelier/resources/views/portal/marketing-cancelar.blade.php' <<'__ATELIER_EOF__'
@extends('atelier::layouts.publico')

@section('titulo', 'Mensagens de marketing')

@section('conteudo')
    <div class="mx-auto max-w-md rounded-md border border-slate-200 bg-white p-6 text-center">
        @if ($concluido)
            <h1 class="mb-2 text-lg font-semibold text-slate-900">Pedido registado</h1>
            <p class="text-sm text-slate-600">
                Deixaste de receber mensagens de marketing de {{ $empresa?->nome_comercial ?? 'este atelier' }}.
                Continuarás a receber avisos sobre os teus pedidos e provas.
            </p>
        @else
            <h1 class="mb-2 text-lg font-semibold text-slate-900">Deixar de receber mensagens?</h1>
            <p class="mb-4 text-sm text-slate-600">
                Olá, {{ $cliente->nome }}. Confirma para deixares de receber campanhas e mensagens de aniversário de
                {{ $empresa?->nome_comercial ?? 'este atelier' }}.
            </p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Confirmar
                </button>
            </form>
        @endif
    </div>
@endsection
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# MODELS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Models/ClienteCrm.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

/**
 * Extensão de CRM sobre Modules\Faturacao\Models\Cliente (ver decisão de
 * arquitetura no README: guardado aqui para não alterar o schema do módulo
 * Faturacao). Criado sob demanda — nem todo cliente tem uma linha aqui.
 *
 * Marketing: só há consentimento VÁLIDO quando aceita_marketing é true, há
 * data de consentimento e não foi revogado. Ausência de linha, ou de data,
 * significa SEM consentimento (Lei n.º 22/11).
 */
class ClienteCrm extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_clientes_crm';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'data_nascimento',
        'aceita_marketing',
        'consentimento_em',
        'consentimento_origem',
        'consentimento_revogado_em',
        'notas',
    ];

    protected $attributes = [
        'aceita_marketing' => false,
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'aceita_marketing' => 'boolean',
            'consentimento_em' => 'datetime',
            'consentimento_revogado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function temConsentimentoValido(): bool
    {
        return $this->aceita_marketing
            && $this->consentimento_em !== null
            && $this->consentimento_revogado_em === null;
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Models/PedidoOrcamento.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\BelongsToTenant;

/**
 * Lead do formulário público de orçamento. Usa BelongsToTenant mesmo sendo
 * criado sem autenticação: quando não há tenant definido, a trait respeita
 * o empresa_id explícito (vindo do slug da landing page) em vez de o
 * ignorar — ver o comentário na migration e em BelongsToTenant.
 *
 * consentimento_em: momento em que o visitante aceitou o tratamento dos
 * dados para responder ao pedido.
 */
class PedidoOrcamento extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_pedidos_orcamento';

    protected $fillable = [
        'empresa_id',
        'nome',
        'contacto',
        'categoria_interesse',
        'mensagem',
        'estado',
        'consentimento_em',
    ];

    protected $attributes = [
        'estado' => 'novo',
    ];

    protected function casts(): array
    {
        return [
            'consentimento_em' => 'datetime',
        ];
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Models/PedidoFoto.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotos de referência de um pedido. São dados pessoais: ficam no disco
 * PRIVADO e só são servidas por rota autenticada (FotoController).
 * DISCO_LEGADO existe apenas para ficheiros antigos ainda não movidos.
 */
class PedidoFoto extends Model
{
    public const DISCO = 'local';

    public const DISCO_LEGADO = 'public';

    protected $table = 'atelier_pedido_fotos';

    protected $fillable = ['pedido_id', 'caminho', 'legenda'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function url(): string
    {
        return route('atelier.pedidos.foto', $this->id);
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# SERVIÇOS: portal (links) e CRM (consentimento)
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Services/PortalLinkService.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Modules\Faturacao\Models\Cliente;

/**
 * Gera o "link mágico" do Portal do Cliente (requisito E) como uma signed
 * URL do Laravel — sem tabela de tokens, sem sessão própria. A assinatura
 * cobre o cliente_id e a expiração; a rota valida com o middleware
 * 'signed' nativo. Isto é deliberadamente mais simples que uma sessão
 * autenticada tradicional — ver o aviso no README sobre as suas limitações
 * (um link partilhado dá acesso a quem o tiver, até expirar).
 *
 * Os links gerados a partir de uma página do portal já aberta com um link
 * assinado NUNCA expiram depois desse link: sem isto, quem tivesse um link
 * conseguia renová-lo indefinidamente (cada ação devolvia um link novo).
 */
class PortalLinkService
{
    public function gerarLinkAcesso(Cliente $cliente): string
    {
        return URL::temporarySignedRoute(
            'atelier.portal.mostrar',
            $this->expiracao(),
            ['cliente' => $cliente->id],
        );
    }

    /**
     * Um único nome de rota serve tanto o GET (mostrar formulário) como o
     * POST (submeter) — a validação de assinatura do Laravel não depende do
     * verbo HTTP, só do path+parâmetros+signature, por isso o mesmo link
     * funciona para os dois (ver routes/web.php, Route::match(['get','post'])).
     */
    public function gerarLinkReagendamento(Cliente $cliente, int $provaId): string
    {
        return URL::temporarySignedRoute(
            'atelier.portal.provas.reagendar',
            $this->expiracao(),
            ['cliente' => $cliente->id, 'prova' => $provaId],
        );
    }

    /**
     * Link de "deixar de receber marketing": sem expiração (tem de funcionar
     * em mensagens antigas) e só permite revogar o consentimento — não dá
     * acesso a dados nem a qualquer outra ação.
     */
    public function gerarLinkCancelarMarketing(Cliente $cliente): string
    {
        return URL::signedRoute('atelier.portal.marketing.cancelar', ['cliente' => $cliente->id]);
    }

    protected function expiracao(): Carbon
    {
        $padrao = now()->addDays((int) config('atelier.portal_link_validade_dias'));
        $atual = request()->query('expires');

        if (is_numeric($atual)) {
            $limite = Carbon::createFromTimestamp((int) $atual);

            if ($limite->isFuture() && $limite->lessThan($padrao)) {
                return $limite;
            }
        }

        return $padrao;
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Services/CrmService.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Collection;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Faturacao\Models\Cliente;

/**
 * Resolve "segmentos" de clientes para o CRM/Marketing (requisito D).
 * Consulta sempre a partir de Pedido/ClienteCrm (models do Atelier) e nunca
 * acrescenta relações a Modules\Faturacao\Models\Cliente — mantém o módulo
 * Faturacao intocado (ver ClienteCrm e a migration correspondente).
 *
 * CONSENTIMENTO (Lei n.º 22/11): marketing é OPT-IN. Só recebe quem tem um
 * ClienteCrm com consentimento válido (aceita_marketing, data de
 * consentimento registada e sem revogação). Cliente sem linha CRM = sem
 * consentimento. Todas as consultas filtram explicitamente por empresa_id
 * porque estes métodos também correm em jobs, sem tenant definido.
 */
class CrmService
{
    public function aniversariantesHoje(int $empresaId): Collection
    {
        $hoje = now();

        $clienteIds = $this->consentimentos($empresaId)
            ->whereNotNull('data_nascimento')
            ->where(function ($q) use ($hoje) {
                $q->where(fn ($d) => $d->whereMonth('data_nascimento', $hoje->month)->whereDay('data_nascimento', $hoje->day));

                // Quem faz anos a 29/02 celebra a 28/02 nos anos não bissextos.
                if ($hoje->month === 2 && $hoje->day === 28 && ! $hoje->isLeapYear()) {
                    $q->orWhere(fn ($d) => $d->whereMonth('data_nascimento', 2)->whereDay('data_nascimento', 29));
                }
            })
            ->pluck('cliente_id');

        return $this->clientes($empresaId, $clienteIds);
    }

    /** Clientes com pelo menos um pedido, mas nenhum nos últimos $meses (e com consentimento). */
    public function clientesInativos(int $empresaId, int $meses): Collection
    {
        $dataLimite = now()->subMonths($meses);

        $idsInativos = Pedido::query()
            ->where('empresa_id', $empresaId)
            ->select('cliente_id')
            ->groupBy('cliente_id')
            ->havingRaw('MAX(created_at) < ?', [$dataLimite])
            ->pluck('cliente_id');

        $comConsentimento = $this->consentimentos($empresaId)->pluck('cliente_id');

        return $this->clientes($empresaId, $idsInativos->intersect($comConsentimento));
    }

    public function resolverSegmento(Campanha $campanha): Collection
    {
        $empresaId = (int) $campanha->empresa_id;

        return match ($campanha->segmento) {
            'todos' => $this->clientes($empresaId, $this->consentimentos($empresaId)->pluck('cliente_id')),
            'aniversariantes_mes' => $this->clientes(
                $empresaId,
                $this->consentimentos($empresaId)
                    ->whereNotNull('data_nascimento')
                    ->whereMonth('data_nascimento', now()->month)
                    ->pluck('cliente_id'),
            ),
            'inativos' => $this->clientesInativos($empresaId, (int) config('atelier.meses_inatividade_reativacao')),
            default => collect(),
        };
    }

    /** Registos CRM com consentimento de marketing válido (data registada, não revogado). */
    protected function consentimentos(int $empresaId)
    {
        return ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->where('aceita_marketing', true)
            ->whereNotNull('consentimento_em')
            ->whereNull('consentimento_revogado_em');
    }

    protected function clientes(int $empresaId, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Cliente::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ids->unique()->values())
            ->get();
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# PEDIDOS: exceção, model, regras de estado, autorização, controller
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Exceptions/PedidoException.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Exceptions;

use Exception;

/**
 * Mesma ideia de AssinaturaFiscalException: uma exceção de domínio com
 * fábricas estáticas nomeadas, para que quem apanha a exceção (controller,
 * job) saiba exatamente que erro de negócio aconteceu sem parsear mensagens.
 */
class PedidoException extends Exception
{
    public static function transicaoInvalida(string $de, string $para): self
    {
        return new self("Não é possível mudar o pedido de \"{$de}\" para \"{$para}\".");
    }

    public static function sinalJaRegistado(): self
    {
        return new self('Este pedido já tem um sinal/adiantamento registado.');
    }

    public static function faturaFinalJaGerada(): self
    {
        return new self('Este pedido já tem uma fatura final gerada.');
    }

    public static function clienteSemDadosFaturacao(): self
    {
        return new self('Este cliente não tem NIF/dados suficientes para faturação — verifica a ficha do cliente no módulo Faturação.');
    }

    public static function valorInvalido(): self
    {
        return new self('O valor indicado tem de ser maior que zero.');
    }

    public static function entregaSemFaturaEmitida(): self
    {
        return new self('Não é possível marcar como entregue sem a fatura final emitida: gera e emite a fatura antes da entrega.');
    }

    public static function entregaComSaldoPorPagar(): self
    {
        return new self('Não é possível marcar como entregue: falta registar o pagamento do saldo final.');
    }

    public static function cancelamentoComFaturaEmitida(): self
    {
        return new self('Este pedido já tem fatura final emitida e não pode ser cancelado aqui. Anula primeiro a fatura com uma nota de crédito no módulo Faturação.');
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Models/Pedido.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Dinheiro;

/**
 * O Pedido de Costura/Peça. Ao contrário de Fatura/Recibo, este model NÃO
 * usa a trait Imutavel — é um registo operacional que precisa de ser
 * editável ao longo do fluxo (mudar de estado, reatribuir responsável,
 * ajustar prazos). A única imutabilidade real que importa aqui é a dos
 * DOCUMENTOS FISCAIS que este pedido gera (fatura_id, recibo_sinal_id,
 * recibo_saldo_final_id) — esses sim, protegidos pela Imutavel dentro do
 * próprio módulo Faturacao; o Pedido só guarda a referência.
 *
 * Transições de estado são validadas em PedidoStatusService, não aqui —
 * mantém o model simples e a regra de negócio num único sítio testável.
 */
class Pedido extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_pedidos';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'medida_id',
        'responsavel_id',
        'tipo_servico',
        'descricao',
        'tecido_cor',
        'aviamentos_necessarios',
        'status',
        'motivo_cancelamento',
        'data_prevista_entrega',
        'prazo_interno',
        'valor_orcamento',
        'taxa_iva_aplicada',
        'percentual_sinal',
        'valor_sinal',
        'recibo_sinal_id',
        'fatura_id',
        'recibo_saldo_final_id',
    ];

    protected $attributes = [
        'status' => 'pendente',
    ];

    protected function casts(): array
    {
        return [
            'data_prevista_entrega' => 'date',
            'prazo_interno' => 'date',
            'valor_orcamento' => 'decimal:2',
            'taxa_iva_aplicada' => 'decimal:2',
            'percentual_sinal' => 'decimal:2',
            'valor_sinal' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function medida(): BelongsTo
    {
        return $this->belongsTo(Medida::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(PedidoFoto::class);
    }

    public function materiais(): HasMany
    {
        return $this->hasMany(PedidoMaterial::class)->orderBy('ordem');
    }

    public function provas(): HasMany
    {
        return $this->hasMany(Prova::class);
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    public function reciboSinal(): BelongsTo
    {
        return $this->belongsTo(Recibo::class, 'recibo_sinal_id');
    }

    public function reciboSaldoFinal(): BelongsTo
    {
        return $this->belongsTo(Recibo::class, 'recibo_saldo_final_id');
    }

    public function estaFinalizado(): bool
    {
        return in_array($this->status, config('atelier.estados_finais'), true);
    }

    public function jaTemSinalRegistado(): bool
    {
        return $this->recibo_sinal_id !== null;
    }

    public function jaTemFaturaFinal(): bool
    {
        return $this->fatura_id !== null;
    }

    /**
     * Total do pedido com IVA, em cêntimos, calculado LINHA A LINHA com a
     * mesma regra de arredondamento (Dinheiro::calcularLinha) que o
     * PedidoFaturacaoService usa ao montar a fatura — assim o total do
     * pedido, o limite do sinal e o saldo coincidem ao cêntimo com a
     * fatura final. (Antes somava tudo em float e arredondava no fim, o
     * que podia divergir da fatura em cêntimos.)
     */
    public function totalComIvaEmCentimos(): int
    {
        $taxa = (string) $this->taxa_iva_aplicada;

        $total = Dinheiro::centimos(
            Dinheiro::calcularLinha(1, (string) $this->valor_orcamento, $taxa)['total']
        );

        foreach ($this->materiais as $material) {
            $total += Dinheiro::centimos(
                Dinheiro::calcularLinha((string) $material->quantidade, (string) $material->valor_unitario, $taxa)['total']
            );
        }

        return $total;
    }

    public function valorTotalComIva(): float
    {
        return $this->totalComIvaEmCentimos() / 100;
    }

    /**
     * Saldo em falta para a entrega, depois de descontado o sinal já pago.
     * Usado tanto na view do pedido como por PedidoFaturacaoService ao
     * emitir o recibo do saldo final.
     */
    public function saldoEmFaltaEmCentimos(): int
    {
        return $this->totalComIvaEmCentimos() - Dinheiro::centimos($this->valor_sinal ?? 0);
    }

    public function saldoEmFalta(): float
    {
        return $this->saldoEmFaltaEmCentimos() / 100;
    }

    public function tipoServicoRotulo(): string
    {
        return config("atelier.tipos_servico.{$this->tipo_servico}", $this->tipo_servico);
    }

    public function statusRotulo(): string
    {
        return self::rotuloParaStatus($this->status);
    }

    public static function rotuloParaStatus(string $status): string
    {
        return match ($status) {
            'pendente' => 'Pendente',
            'em_corte' => 'Em Corte',
            'em_costura' => 'Em Costura',
            'primeira_prova' => 'Primeira Prova',
            'ajustes' => 'Ajustes',
            'pronto_para_retirada' => 'Pronto para Retirada',
            'entregue' => 'Entregue',
            'cancelado' => 'Cancelado',
            default => $status,
        };
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Services/PedidoStatusService.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Support\Dinheiro;

class PedidoStatusService
{
    private const TRANSICOES_PERMITIDAS = [
        'pendente' => ['em_corte', 'cancelado'],
        'em_corte' => ['em_costura', 'cancelado'],
        'em_costura' => ['primeira_prova', 'cancelado'],
        'primeira_prova' => ['ajustes', 'pronto_para_retirada', 'cancelado'],
        'ajustes' => ['primeira_prova', 'pronto_para_retirada', 'cancelado'],
        'pronto_para_retirada' => ['entregue', 'cancelado'],
        'entregue' => [],
        'cancelado' => [],
    ];

    /**
     * @param  bool  $validarFaturacao  Quando true (default), a entrega exige a
     *                                  fatura final emitida e o saldo pago, e o
     *                                  cancelamento é recusado se já há fatura
     *                                  emitida (exige nota de crédito). Só
     *                                  desligar em testes/scripts de dados.
     */
    public function avancarPara(
        Pedido $pedido,
        string $novoEstado,
        ?string $motivoCancelamento = null,
        bool $validarFaturacao = true,
    ): Pedido {
        [$estadoAnterior] = DB::transaction(function () use ($pedido, $novoEstado, $motivoCancelamento, $validarFaturacao) {
            // Recarrega o estado dentro da transação e bloqueia a linha.
            // Sem isto, dois pedidos concorrentes podiam validar a mesma
            // transição antiga e depois sobrescrever um ao outro.
            $bloqueado = Pedido::query()
                ->where('empresa_id', $pedido->empresa_id)
                ->lockForUpdate()
                ->findOrFail($pedido->id);

            $estadoAnterior = $bloqueado->status;
            $permitidos = self::TRANSICOES_PERMITIDAS[$estadoAnterior] ?? [];

            if (! in_array($novoEstado, $permitidos, true)) {
                throw PedidoException::transicaoInvalida($estadoAnterior, $novoEstado);
            }

            if ($validarFaturacao) {
                if ($novoEstado === 'entregue') {
                    $this->exigirFaturacaoRegularizada($bloqueado);
                }

                if ($novoEstado === 'cancelado') {
                    $this->exigirQueNaoHaFaturaEmitida($bloqueado);
                }
            }

            $bloqueado->status = $novoEstado;

            if ($novoEstado === 'cancelado') {
                $bloqueado->motivo_cancelamento = $motivoCancelamento;
            }

            $bloqueado->save();

            // Mantém a instância que o controller entregou sincronizada,
            // com os dados relidos e gravados sob lock.
            $pedido->setRawAttributes($bloqueado->getAttributes(), true);

            Log::info('Pedido mudou de estado', [
                'pedido_id' => $bloqueado->id,
                'empresa_id' => $bloqueado->empresa_id,
                'de' => $estadoAnterior,
                'para' => $novoEstado,
            ]);

            return [$estadoAnterior];
        });

        // O evento só é disparado DEPOIS do commit: os listeners (notificação
        // ao cliente por WhatsApp/SMS/mail) nunca veem — nem avisam o cliente
        // de — um estado que acabou em rollback.
        event(new PedidoMudouEstado($pedido, $estadoAnterior));

        return $pedido;
    }

    /** @return array<int, string> Estados para os quais este pedido pode avançar agora — usado para desenhar os botões de ação na view. */
    public function proximosEstadosPossiveis(Pedido $pedido): array
    {
        return self::TRANSICOES_PERMITIDAS[$pedido->status] ?? [];
    }

    /** Entrega: fatura final emitida e, se sobrar saldo depois do sinal, recibo do saldo registado. */
    protected function exigirFaturacaoRegularizada(Pedido $pedido): void
    {
        $fatura = $this->faturaDe($pedido);

        if (! $fatura || ! $fatura->estaEmitido()) {
            throw PedidoException::entregaSemFaturaEmitida();
        }

        $saldo = Dinheiro::centimos($fatura->valor_total) - Dinheiro::centimos($pedido->valor_sinal ?? 0);

        if ($saldo > 0 && $pedido->recibo_saldo_final_id === null) {
            throw PedidoException::entregaComSaldoPorPagar();
        }
    }

    /** Cancelar um pedido com fatura emitida deixaria um documento fiscal órfão — exige nota de crédito. */
    protected function exigirQueNaoHaFaturaEmitida(Pedido $pedido): void
    {
        $fatura = $this->faturaDe($pedido);

        if ($fatura && $fatura->estaEmitido()) {
            throw PedidoException::cancelamentoComFaturaEmitida();
        }
    }

    protected function faturaDe(Pedido $pedido): ?Fatura
    {
        if (! $pedido->jaTemFaturaFinal()) {
            return null;
        }

        return Fatura::withoutGlobalScopes()
            ->where('empresa_id', $pedido->empresa_id)
            ->find($pedido->fatura_id);
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Requests/AvancarEstadoPedidoRequest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Atelier\Models\Pedido;

class AvancarEstadoPedidoRequest extends FormRequest
{
    /**
     * Requisito G.3: a Costureira vê/gere o painel de tarefas mas não tem
     * acesso a dados financeiros — por extensão, também não é ela quem
     * marca a entrega final ou o cancelamento de um pedido (ambos com
     * implicações financeiras/de relação com o cliente).
     */
    private const ESTADOS_RESTRITOS_A_GESTAO = ['entregue', 'cancelado'];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['Administrador', 'Secretária'])) {
            return true;
        }

        if ($user->hasRole('Costureira')) {
            if (in_array($this->input('novo_estado'), self::ESTADOS_RESTRITOS_A_GESTAO, true)) {
                return false;
            }

            // A Costureira só avança os pedidos que lhe estão atribuídos.
            // Pedido inexistente: deixa passar para o controller devolver 404.
            $pedido = Pedido::query()->find((int) $this->route('pedido'));

            return $pedido === null || (int) $pedido->responsavel_id === (int) $user->id;
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'novo_estado' => ['required', 'string', 'in:pendente,em_corte,em_costura,primeira_prova,ajustes,pronto_para_retirada,entregue,cancelado'],
            'motivo_cancelamento' => ['required_if:novo_estado,cancelado', 'nullable', 'string', 'max:1000'],
        ];
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Http\Requests\AtualizarPedidoRequest;
use Modules\Atelier\Http\Requests\AvancarEstadoPedidoRequest;
use Modules\Atelier\Http\Requests\GerarFaturaFinalRequest;
use Modules\Atelier\Http\Requests\GuardarPedidoRequest;
use Modules\Atelier\Http\Requests\RegistarPagamentoFinalRequest;
use Modules\Atelier\Http\Requests\RegistarSinalRequest;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoFoto;
use Modules\Atelier\Models\PedidoMaterial;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Atelier\Services\PedidoStatusService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Throwable;

class PedidoController extends Controller
{
    public function __construct(
        protected PedidoStatusService $statusService,
        protected PedidoFaturacaoService $faturacaoService,
        protected PortalLinkService $portalLinkService,
    ) {}

    public function index(): View
    {
        $query = Pedido::with(['cliente', 'responsavel'])->latest('id');

        // A Costureira só vê os pedidos que lhe estão atribuídos.
        if (! $this->podeGerir()) {
            $query->where('responsavel_id', request()->user()->id);
        }

        return view('atelier::pedidos.index', [
            'pedidos' => $query->paginate(20),
        ]);
    }

    public function criar(): View
    {
        abort_unless($this->podeGerir(), 403);

        return view('atelier::pedidos.criar', [
            'clientes' => Cliente::orderBy('nome')->get(),
            'costureiras' => request()->user()->empresa->utilizadores()->orderBy('name')->get(),
            'tiposServico' => config('atelier.tipos_servico'),
        ]);
    }

    public function guardar(GuardarPedidoRequest $request): RedirectResponse
    {
        $pedido = DB::transaction(function () use ($request) {
            $pedido = Pedido::create([
                'cliente_id' => $request->validated('cliente_id'),
                'medida_id' => $request->validated('medida_id'),
                'responsavel_id' => $request->validated('responsavel_id'),
                'tipo_servico' => $request->validated('tipo_servico'),
                'descricao' => $request->validated('descricao'),
                'tecido_cor' => $request->validated('tecido_cor'),
                'aviamentos_necessarios' => $request->validated('aviamentos_necessarios'),
                'data_prevista_entrega' => $request->validated('data_prevista_entrega'),
                'prazo_interno' => $request->validated('prazo_interno'),
                'valor_orcamento' => $request->validated('valor_orcamento'),
                'percentual_sinal' => $request->validated('percentual_sinal'),
                'taxa_iva_aplicada' => (float) config('faturacao.taxa_iva_geral'),
            ]);

            foreach ($request->validated('materiais', []) as $indice => $dadosMaterial) {
                $material = new PedidoMaterial([
                    'pedido_id' => $pedido->id,
                    'descricao' => $dadosMaterial['descricao'],
                    'quantidade' => $dadosMaterial['quantidade'],
                    'valor_unitario' => $dadosMaterial['valor_unitario'],
                    'ordem' => $indice,
                ]);
                $material->calcularValores()->save();
            }

            // Fotos de clientes são dados pessoais: disco PRIVADO, servidas
            // só por rota autenticada (Admin\FotoController).
            foreach ($request->file('fotos', []) as $ficheiro) {
                $caminho = $ficheiro->store('atelier/pedidos', PedidoFoto::DISCO);
                $pedido->fotos()->create(['caminho' => $caminho]);
            }

            return $pedido;
        });

        return redirect()->route('atelier.pedidos.mostrar', $pedido)
            ->with('sucesso', 'Pedido criado.');
    }

    public function mostrar(int $pedido): View
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirAcessoAoPedido($pedido);

        $pedido->load(['cliente', 'medida', 'responsavel', 'fotos', 'materiais', 'provas', 'fatura', 'reciboSinal', 'reciboSaldoFinal']);

        return view('atelier::pedidos.mostrar', [
            'pedido' => $pedido,
            'proximosEstados' => $this->statusService->proximosEstadosPossiveis($pedido),
            'podeEmitirFaturas' => $pedido->empresa->podeEmitirFaturas(),
            'linkPortal' => $pedido->cliente ? $this->portalLinkService->gerarLinkAcesso($pedido->cliente) : null,
            // Requisito G.3: a Costureira vê o painel de tarefas mas não
            // dados financeiros — a view usa isto para esconder valores,
            // sinal, fatura e recibos, não só os formulários de ação.
            'podeVerFinancas' => $this->podeGerir(),
        ]);
    }

    public function atualizar(AtualizarPedidoRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $pedido->update($request->validated());

        return back()->with('sucesso', 'Pedido atualizado.');
    }

    public function avancarEstado(AvancarEstadoPedidoRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);

        try {
            $this->statusService->avancarPara(
                $pedido,
                $request->validated('novo_estado'),
                $request->validated('motivo_cancelamento'),
            );
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        }

        $mensagem = 'Estado do pedido atualizado.';

        if ($pedido->status === 'cancelado' && $pedido->jaTemSinalRegistado()) {
            $mensagem .= ' Atenção: este pedido tinha um sinal recebido — regulariza a devolução ou retenção desse valor com a contabilidade.';
        }

        return back()->with('sucesso', $mensagem);
    }

    public function registarSinal(RegistarSinalRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirQuePodeEmitirFaturas($pedido->empresa);

        try {
            $this->faturacaoService->registarSinal($pedido, (float) $request->validated('valor'), $request->validated('meio_pagamento'));
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao registar sinal do Atelier', ['pedido_id' => $pedido->id, 'erro' => $e->getMessage()]);

            return back()->with('erro', 'Ocorreu um erro ao registar o sinal. Tenta novamente.');
        }

        return back()->with('sucesso', 'Sinal registado e recibo emitido.');
    }

    public function gerarFaturaFinal(GerarFaturaFinalRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirQuePodeEmitirFaturas($pedido->empresa);

        try {
            $fatura = $this->faturacaoService->gerarFaturaFinalRascunho($pedido, $request->validated('observacoes'));
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('faturacao.faturas.mostrar', $fatura)
            ->with('sucesso', 'Rascunho da fatura final criado — revê os valores e emite-a aqui.');
    }

    public function registarPagamentoFinal(RegistarPagamentoFinalRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirQuePodeEmitirFaturas($pedido->empresa);

        try {
            $this->faturacaoService->registarPagamentoFinal($pedido, $request->validated('meio_pagamento'));
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao registar pagamento final do Atelier', ['pedido_id' => $pedido->id, 'erro' => $e->getMessage()]);

            return back()->with('erro', 'Ocorreu um erro ao registar o pagamento. Tenta novamente.');
        }

        return back()->with('sucesso', 'Pagamento do saldo registado e recibo emitido.');
    }

    /** Administrador e Secretária gerem tudo; os restantes (Costureira) só os pedidos que lhes estão atribuídos. */
    protected function podeGerir(): bool
    {
        return (bool) request()->user()?->hasAnyRole(['Administrador', 'Secretária']);
    }

    protected function garantirAcessoAoPedido(Pedido $pedido): void
    {
        abort_unless(
            $this->podeGerir() || (int) $pedido->responsavel_id === (int) request()->user()->id,
            403
        );
    }

    /** Mesma distinção que FaturaController já aplica: grace period dá acesso de leitura, mas não deixa emitir novos documentos fiscais. */
    protected function garantirQuePodeEmitirFaturas(Empresa $empresa): void
    {
        abort_unless(
            $empresa->podeEmitirFaturas(),
            402,
            'A tua empresa está no período de tolerância — não é possível gerar novos documentos fiscais até regularizares a subscrição.'
        );
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# CAMPANHAS E JOBS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarCampanhaRequest;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;

/**
 * IMPORTANTE: {campanha} chega como int, nunca como Campanha tipado (route
 * model binding implícito). O Laravel resolve o binding ANTES do
 * middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver sempre
 * 404. O findOrFail() dentro de cada método já corre com o tenant certo.
 * Mesmo padrão de Modules\Faturacao\Http\Controllers\FaturaController.
 *
 * Marketing é só para Administradores — em TODAS as ações, não só ao criar.
 */
class CampanhaController extends Controller
{
    public function index(Request $request): View
    {
        $this->autorizar($request);

        return view('atelier::campanhas.index', [
            'campanhas' => Campanha::latest('id')->paginate(20),
        ]);
    }

    public function criar(Request $request): View
    {
        $this->autorizar($request);

        return view('atelier::campanhas.criar');
    }

    public function guardar(GuardarCampanhaRequest $request): RedirectResponse
    {
        $campanha = Campanha::create([
            ...$request->validated(),
            'criada_por_id' => $request->user()->id,
            'estado' => $request->validated('agendada_para') ? 'agendada' : 'rascunho',
        ]);

        return redirect()->route('atelier.campanhas.mostrar', $campanha)->with('sucesso', 'Campanha criada.');
    }

    public function mostrar(Request $request, int $campanha): View
    {
        $this->autorizar($request);

        $campanha = Campanha::findOrFail($campanha);

        return view('atelier::campanhas.mostrar', [
            'campanha' => $campanha->load('envios.cliente'),
        ]);
    }

    public function enviarAgora(Request $request, int $campanha): RedirectResponse
    {
        $this->autorizar($request);

        $campanha = Campanha::findOrFail($campanha);

        abort_if($campanha->estado === 'cancelada', 422, 'Esta campanha foi cancelada.');

        // Campanha já enviada só pode ser relançada para reenviar os envios
        // que falharam (o job ignora os já enviados e respeita o consentimento atual).
        abort_if(
            $campanha->estado === 'enviada' && ! $campanha->envios()->where('estado', 'falhou')->exists(),
            422,
            'Esta campanha já foi enviada.'
        );

        // Um clique duplo despacha dois jobs, mas o job tem um lock por
        // campanha e é idempotente: o segundo não reenvia nada.
        DispararCampanhaJob::dispatch($campanha->id);

        return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
    }

    protected function autorizar(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Administrador'), 403);
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Jobs/DispararCampanhaJob.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Processa UMA campanha (requisito D.1) — disparado tanto pelo botão
 * "Enviar agora" (Admin\CampanhaController) como por
 * EnviarCampanhasAgendadasJob quando agendada_para chega. Recebe só o ID
 * (nunca o model) no construtor: um Job ShouldQueue é serializado para a
 * fila, e passar o ID evita problemas de serialização/dados desatualizados
 * se o worker só processar o Job mais tarde.
 *
 * Idempotente por desenho: a unique constraint em atelier_campanha_envios
 * (campanha_id, cliente_id), combinada com firstOrCreate() + verificação do
 * estado antes de reenviar, torna seguro correr este Job mais do que uma
 * vez para a mesma campanha (ex.: um retry automático da fila).
 *
 * Concorrência: um lock por campanha impede dois jobs simultâneos (clique
 * duplo, ou o agendador a re-despachar enquanto o anterior ainda corre).
 *
 * Reenvio: numa campanha já 'enviada' só se reprocessam os envios que
 * falharam/ficaram pendentes — nunca se alarga o público.
 *
 * Marketing: cada mensagem leva o link para o cliente deixar de receber
 * campanhas (Lei n.º 22/11), e o público é sempre só quem tem consentimento.
 */
class DispararCampanhaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(protected int $campanhaId) {}

    public function handle(
        TenantManager $tenantManager,
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
    ): void {
        $lock = Cache::lock('atelier:campanha:'.$this->campanhaId, $this->timeout + 60);

        if (! $lock->get()) {
            // Já há um job a processar esta campanha.
            return;
        }

        try {
            $tenantManager->semTenant(fn () => $this->processar($crmService, $notificador, $linkService));
        } finally {
            $lock->release();
        }
    }

    protected function processar(CrmService $crmService, NotificadorClienteService $notificador, PortalLinkService $linkService): void
    {
        $campanha = Campanha::find($this->campanhaId);

        if (! $campanha || $campanha->estado === 'cancelada') {
            return;
        }

        $empresa = Empresa::find($campanha->empresa_id);

        if (! $empresa || ! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
            Log::info('Atelier: campanha não enviada — empresa sem acesso ou sem o serviço atelier', [
                'campanha_id' => $campanha->id,
                'empresa_id' => $campanha->empresa_id,
            ]);

            return;
        }

        $reenvio = $campanha->estado === 'enviada';
        $idsPorReenviar = collect();

        if ($reenvio) {
            $idsPorReenviar = $campanha->envios()->whereIn('estado', ['falhou', 'pendente'])->pluck('cliente_id');

            if ($idsPorReenviar->isEmpty()) {
                return;
            }
        }

        $clientes = $crmService->resolverSegmento($campanha);

        if ($reenvio) {
            $clientes = $clientes->whereIn('id', $idsPorReenviar)->values();
        }

        $assunto = $campanha->assunto ?: $campanha->nome;

        foreach ($clientes as $cliente) {
            $envio = CampanhaEnvio::firstOrCreate(
                ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
                ['canal' => $campanha->canal, 'estado' => 'pendente'],
            );

            if ($envio->estado === 'enviado') {
                continue;
            }

            $mensagem = $campanha->mensagem
                ."\n\nPara deixares de receber estas mensagens: ".$linkService->gerarLinkCancelarMarketing($cliente);

            try {
                $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $mensagem);
                $envio->update(['estado' => 'enviado', 'enviado_em' => now(), 'erro' => null]);
            } catch (NotificacaoException|Throwable $e) {
                $envio->update(['estado' => 'falhou', 'erro' => $e->getMessage()]);

                Log::warning('Atelier: falha ao enviar campanha para um cliente', [
                    'campanha_id' => $campanha->id,
                    'cliente_id' => $cliente->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $campanha->update([
            'estado' => 'enviada',
            'enviada_em' => $campanha->enviada_em ?? now(),
        ]);
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Jobs/VerificarAniversariosJob.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Único gatilho de CRM verdadeiramente AUTOMÁTICO (requisito D.1: "Mensagens
 * automáticas em datas comemorativas") — corre uma vez por dia. As
 * promoções sazonais e a reativação de inativos são, de propósito,
 * campanhas manuais (ver Admin\CampanhaController) e não entram aqui.
 *
 * Só processa empresas com acesso ativo E com o serviço atelier contratado,
 * só envia a clientes com consentimento de marketing (CrmService) e nunca
 * envia duas vezes ao mesmo cliente no mesmo dia (um retry da fila ou uma
 * segunda execução não duplica a mensagem).
 */
class VerificarAniversariosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(
        TenantManager $tenantManager,
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
    ): void {
        $tenantManager->semTenant(function () use ($crmService, $notificador, $linkService) {
            Empresa::query()->chunkById(100, function ($empresas) use ($crmService, $notificador, $linkService) {
                foreach ($empresas as $empresa) {
                    if (! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
                        continue;
                    }

                    try {
                        foreach ($crmService->aniversariantesHoje($empresa->id) as $cliente) {
                            $chave = "atelier:aniversario:{$empresa->id}:{$cliente->id}:".now()->format('Y-m-d');

                            // Cache::add é atómico: só quem o cria no dia envia.
                            if (! Cache::add($chave, 1, now()->addDays(2))) {
                                continue;
                            }

                            $notificador->notificar(
                                $cliente,
                                "Feliz Aniversário! — {$empresa->nome_comercial}",
                                "Hoje é um dia especial! A equipa do {$empresa->nome_comercial} deseja-lhe um feliz aniversário."
                                    ."\n\nPara deixar de receber estas mensagens: ".$linkService->gerarLinkCancelarMarketing($cliente),
                            );
                        }
                    } catch (Throwable $e) {
                        Log::error('Atelier: falha ao processar aniversários da empresa', [
                            'empresa_id' => $empresa->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
        });
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# PORTAL, EQUIPA, ORÇAMENTOS E LANDING
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/app/Http/Controllers/Publico/PortalController.php' <<'__ATELIER_EOF__'
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
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Controllers/Admin/EquipaController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\AtribuirPapelRequest;
use Modules\Atelier\Services\EquipaService;

/**
 * Gere só a ATRIBUIÇÃO de papéis a utilizadores já existentes — ver o aviso
 * em EquipaService sobre a ausência, ainda, de um fluxo de convite de novos
 * utilizadores no Core.
 */
class EquipaController extends Controller
{
    public function __construct(protected EquipaService $equipaService) {}

    public function index(): View
    {
        // A lista de utilizadores e papéis é só para Administradores
        // (atribuir/remover já exigem isso no AtribuirPapelRequest).
        abort_unless(request()->user()?->hasRole('Administrador'), 403);

        return view('atelier::equipa.index', [
            'utilizadores' => request()->user()->empresa->utilizadores()->with('roles')->orderBy('name')->get(),
            'papelSecretaria' => config('atelier.papel_secretaria'),
            'papelCostureira' => config('atelier.papel_costureira'),
        ]);
    }

    public function atribuir(AtribuirPapelRequest $request): RedirectResponse
    {
        // User não usa BelongsToTenant (não podia: o próprio Auth::user()
        // que identifica o tenant corre ANTES de haver um tenant definido —
        // ver IdentificarTenant). Por isso a filtragem por empresa aqui tem
        // de ser explícita, via a relação, e não pode confiar num scope
        // automático.
        $utilizador = $request->user()->empresa->utilizadores()->findOrFail($request->validated('utilizador_id'));

        $this->equipaService->atribuirPapel($utilizador, $request->validated('papel'));

        return back()->with('sucesso', "Papel \"{$request->validated('papel')}\" atribuído a {$utilizador->name}.");
    }

    public function remover(AtribuirPapelRequest $request): RedirectResponse
    {
        $utilizador = $request->user()->empresa->utilizadores()->findOrFail($request->validated('utilizador_id'));

        $this->equipaService->removerPapel($utilizador, $request->validated('papel'));

        return back()->with('sucesso', "Papel removido de {$utilizador->name}.");
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Controllers/Admin/OrcamentoController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Models\PedidoOrcamento;

/**
 * Leads do formulário público da landing page (requisito F.3). Mutação
 * simples de um único campo — mantida sem FormRequest dedicado de
 * propósito, para não multiplicar classes por uma validação trivial.
 *
 * IMPORTANTE: {pedidoOrcamento} chega como int, nunca como PedidoOrcamento
 * tipado (route model binding implícito). O Laravel resolve o binding
 * ANTES do middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver 404.
 *
 * Os leads têm dados pessoais (nome, contacto): só Administrador e
 * Secretária os veem ou alteram.
 */
class OrcamentoController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);

        return view('atelier::orcamentos.index', [
            'orcamentos' => PedidoOrcamento::latest('id')->paginate(20),
        ]);
    }

    public function atualizarEstado(Request $request, int $pedidoOrcamento): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['Administrador', 'Secretária']), 403);

        $pedidoOrcamento = PedidoOrcamento::findOrFail($pedidoOrcamento);

        $dados = $request->validate([
            'estado' => ['required', 'string', 'in:novo,contactado,convertido,descartado'],
        ]);

        $pedidoOrcamento->update($dados);

        return back()->with('sucesso', 'Estado atualizado.');
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Controllers/Publico/LandingController.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Controllers\Publico;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\SolicitarOrcamentoRequest;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Models\PortfolioItem;
use Modules\Core\Models\Empresa;

/**
 * Requisito F. Nenhuma destas queries passa por TenantManager — Empresa,
 * Perfil e PortfolioItem não usam BelongsToTenant, exatamente para que uma
 * página pública os possa ler sem que exista um tenant "autenticado" no
 * pedido (ver as três classes para o porquê).
 */
class LandingController extends Controller
{
    public function mostrar(Empresa $empresa): View
    {
        $perfil = Perfil::where('empresa_id', $empresa->id)->first();

        abort_unless($perfil?->publicado, 404);

        return view('atelier::landing.mostrar', [
            'empresa' => $empresa,
            'perfil' => $perfil,
            'portfolioPorCategoria' => PortfolioItem::where('empresa_id', $empresa->id)
                ->orderBy('ordem')
                ->get()
                ->groupBy('categoria'),
            'categorias' => config('atelier.categorias_portfolio'),
        ]);
    }

    public function solicitarOrcamento(SolicitarOrcamentoRequest $request, Empresa $empresa): RedirectResponse
    {
        abort_unless(
            Perfil::where('empresa_id', $empresa->id)->where('publicado', true)->exists(),
            404
        );

        PedidoOrcamento::create([
            'empresa_id' => $empresa->id,
            'nome' => $request->validated('nome'),
            'contacto' => $request->validated('contacto'),
            'categoria_interesse' => $request->validated('categoria_interesse'),
            'mensagem' => $request->validated('mensagem'),
            // Prova de que o visitante aceitou o tratamento dos dados (Lei n.º 22/11).
            'consentimento_em' => now(),
        ]);

        return back()->with('sucesso', 'Pedido enviado! Entraremos em contacto brevemente.');
    }
}
__ATELIER_EOF__

escrever 'Modules/Atelier/app/Http/Requests/SolicitarOrcamentoRequest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarOrcamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'contacto' => ['required', 'string', 'max:255'],
            'categoria_interesse' => ['nullable', 'string', 'max:255'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
            // Consentimento expresso para tratar os dados (Lei n.º 22/11).
            'consentimento' => ['accepted'],
            // Honeypot: campo invisível que só os robôs preenchem.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'consentimento.accepted' => 'Tens de aceitar o tratamento dos teus dados para podermos responder ao teu pedido.',
            'website.prohibited' => 'Pedido inválido.',
        ];
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# ROTAS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/routes/web.php' <<'__ATELIER_EOF__'
<?php

use Illuminate\Support\Facades\Route;
use Modules\Atelier\Http\Controllers\Admin\CampanhaController;
use Modules\Atelier\Http\Controllers\Admin\ClienteCrmController;
use Modules\Atelier\Http\Controllers\Admin\EquipaController;
use Modules\Atelier\Http\Controllers\Admin\FotoController;
use Modules\Atelier\Http\Controllers\Admin\MedidaController;
use Modules\Atelier\Http\Controllers\Admin\OrcamentoController;
use Modules\Atelier\Http\Controllers\Admin\PedidoController;
use Modules\Atelier\Http\Controllers\Admin\PerfilController;
use Modules\Atelier\Http\Controllers\Admin\PortfolioController;
use Modules\Atelier\Http\Controllers\Admin\ProvaController;
use Modules\Atelier\Http\Controllers\Publico\LandingController;
use Modules\Atelier\Http\Controllers\Publico\PortalController;

/*
|--------------------------------------------------------------------------
| Área administrativa (autenticada)
|--------------------------------------------------------------------------
| Mesmo grupo de middleware que qualquer rota de negócio do projeto — ver
| Modules/Faturacao/routes/web.php para o precedente exato.
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'servico:atelier'])
    ->prefix('atelier')
    ->name('atelier.')
    ->group(function () {
        Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index');
        Route::get('pedidos/criar', [PedidoController::class, 'criar'])->name('pedidos.criar');
        Route::post('pedidos', [PedidoController::class, 'guardar'])->name('pedidos.guardar');
        Route::get('pedidos/{pedido}', [PedidoController::class, 'mostrar'])->name('pedidos.mostrar');
        Route::put('pedidos/{pedido}', [PedidoController::class, 'atualizar'])->name('pedidos.atualizar');
        Route::post('pedidos/{pedido}/estado', [PedidoController::class, 'avancarEstado'])->name('pedidos.avancar-estado');
        Route::post('pedidos/{pedido}/sinal', [PedidoController::class, 'registarSinal'])->name('pedidos.registar-sinal');
        Route::post('pedidos/{pedido}/fatura-final', [PedidoController::class, 'gerarFaturaFinal'])->name('pedidos.gerar-fatura-final');
        Route::post('pedidos/{pedido}/pagamento-final', [PedidoController::class, 'registarPagamentoFinal'])->name('pedidos.registar-pagamento-final');

        // Fotos de pedidos: dados pessoais, servidas só a utilizadores autorizados.
        Route::get('pedidos/fotos/{foto}', [FotoController::class, 'mostrar'])->name('pedidos.foto');

        Route::post('pedidos/{pedido}/provas', [ProvaController::class, 'guardar'])->name('provas.guardar');
        Route::put('provas/{prova}', [ProvaController::class, 'atualizarEstado'])->name('provas.atualizar-estado');

        Route::get('clientes/{cliente}/medidas', [MedidaController::class, 'historico'])->name('medidas.historico');
        Route::get('clientes/{cliente}/medidas/criar', [MedidaController::class, 'criar'])->name('medidas.criar');
        Route::post('medidas', [MedidaController::class, 'guardar'])->name('medidas.guardar');

        // Consentimento de marketing e data de nascimento do cliente.
        Route::get('clientes/{cliente}/crm', [ClienteCrmController::class, 'editar'])->name('crm.editar');
        Route::put('clientes/{cliente}/crm', [ClienteCrmController::class, 'atualizar'])->name('crm.atualizar');

        Route::get('equipa', [EquipaController::class, 'index'])->name('equipa.index');
        Route::post('equipa/atribuir', [EquipaController::class, 'atribuir'])->name('equipa.atribuir');
        Route::post('equipa/remover', [EquipaController::class, 'remover'])->name('equipa.remover');

        Route::get('perfil', [PerfilController::class, 'editar'])->name('perfil.editar');
        Route::put('perfil', [PerfilController::class, 'atualizar'])->name('perfil.atualizar');

        Route::get('portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
        Route::post('portfolio', [PortfolioController::class, 'guardar'])->name('portfolio.guardar');
        Route::delete('portfolio/{portfolioItem}', [PortfolioController::class, 'destruir'])->name('portfolio.destruir');

        Route::get('campanhas', [CampanhaController::class, 'index'])->name('campanhas.index');
        Route::get('campanhas/criar', [CampanhaController::class, 'criar'])->name('campanhas.criar');
        Route::post('campanhas', [CampanhaController::class, 'guardar'])->name('campanhas.guardar');
        Route::get('campanhas/{campanha}', [CampanhaController::class, 'mostrar'])->name('campanhas.mostrar');
        Route::post('campanhas/{campanha}/enviar', [CampanhaController::class, 'enviarAgora'])->name('campanhas.enviar-agora');

        Route::get('orcamentos', [OrcamentoController::class, 'index'])->name('orcamentos.index');
        Route::put('orcamentos/{pedidoOrcamento}', [OrcamentoController::class, 'atualizarEstado'])->name('orcamentos.atualizar-estado');
    });

/*
|--------------------------------------------------------------------------
| Portal do cliente (requisito E) — SEM 'auth'/'tenant'. O acesso é a
| assinatura da própria URL (middleware nativo 'signed'); ver
| PortalLinkService e o aviso no README sobre as limitações deste modelo.
| 'throttle' limita tentativas de adivinhar/abusar dos links.
|--------------------------------------------------------------------------
*/
Route::middleware(['throttle:30,1', 'signed'])
    ->prefix('portal/atelier')
    ->name('atelier.portal.')
    ->group(function () {
        Route::get('{cliente}', [PortalController::class, 'mostrar'])->name('mostrar');
        Route::match(['get', 'post'], '{cliente}/provas/{prova}/reagendar', [PortalController::class, 'reagendar'])
            ->name('provas.reagendar');

        // "Deixar de receber marketing" — link sem expiração, só revoga o consentimento.
        Route::match(['get', 'post'], '{cliente}/marketing/cancelar', [PortalController::class, 'cancelarMarketing'])
            ->name('marketing.cancelar');
    });

/*
|--------------------------------------------------------------------------
| Landing page institucional + portfólio (requisito F) — pública, sem
| assinatura (é suposto ser indexável/partilhável livremente). O envio do
| formulário de orçamento é limitado por 'throttle' para reduzir spam.
|--------------------------------------------------------------------------
*/
Route::prefix('loja')
    ->middleware('servico.empresa:atelier')
    ->name('atelier.landing.')
    ->group(function () {
        Route::get('{empresa:slug}', [LandingController::class, 'mostrar'])->name('mostrar');
        Route::post('{empresa:slug}/orcamento', [LandingController::class, 'solicitarOrcamento'])
            ->middleware('throttle:10,1')
            ->name('solicitar-orcamento');
    });
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# LANDING PAGE: aviso de privacidade, consentimento, honeypot, erros
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/resources/views/landing/mostrar.blade.php' <<'__ATELIER_EOF__'
@extends('atelier::layouts.publico')

@section('titulo', $empresa->nome_comercial)
@section('largura', 'max-w-5xl')

@section('conteudo')
    {{-- Cabeçalho --}}
    <header class="mb-12 text-center">
        <h1 class="text-3xl font-semibold text-slate-900">{{ $empresa->nome_comercial }}</h1>
        @if ($perfil->especialidades)
            <p class="mx-auto mt-3 max-w-2xl text-slate-600">{{ $perfil->especialidades }}</p>
        @endif
        <a href="#orcamento" class="mt-6 inline-block rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-500">
            Agende uma consulta / Peça um orçamento
        </a>
    </header>

    {{-- História (requisito F.1) --}}
    @if ($perfil->historia)
        <section class="mb-12">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">A nossa história</h2>
            <p class="whitespace-pre-line text-slate-600">{{ $perfil->historia }}</p>
        </section>
    @endif

    {{-- Equipa (requisito F.1) --}}
    @if (! empty($perfil->equipa))
        <section class="mb-12">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">A equipa</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($perfil->equipa as $membro)
                    <div class="rounded-md border border-slate-200 bg-white p-4">
                        <p class="font-medium text-slate-800">{{ $membro['nome'] ?? '' }}</p>
                        <p class="text-sm text-slate-500">{{ $membro['funcao'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Portfólio com filtro por categoria (requisito F.2). Filtro em Alpine.js, sem
         recarregar a página nem pedidos extra ao servidor. --}}
    @if ($portfolioPorCategoria->isNotEmpty())
        <section class="mb-12" x-data="{ filtro: 'todas' }">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">Portfólio</h2>

            <div class="mb-4 flex flex-wrap gap-2">
                <button type="button" @click="filtro = 'todas'"
                        :class="filtro === 'todas' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 border border-slate-300'"
                        class="rounded-full px-3 py-1 text-xs font-medium">Todas</button>
                @foreach ($portfolioPorCategoria as $categoria => $itens)
                    <button type="button" @click="filtro = '{{ $categoria }}'"
                            :class="filtro === '{{ $categoria }}' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 border border-slate-300'"
                            class="rounded-full px-3 py-1 text-xs font-medium">
                        {{ $categorias[$categoria] ?? $categoria }}
                    </button>
                @endforeach
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ($portfolioPorCategoria as $categoria => $itens)
                    @foreach ($itens as $item)
                        <figure x-show="filtro === 'todas' || filtro === '{{ $categoria }}'"
                                class="overflow-hidden rounded-md border border-slate-200 bg-white">
                            <img src="{{ $item->url() }}" alt="{{ $item->titulo }}" class="h-56 w-full object-cover" loading="lazy">
                            <figcaption class="p-3">
                                <p class="text-sm font-medium text-slate-800">{{ $item->titulo }}</p>
                                @if ($item->descricao)
                                    <p class="text-xs text-slate-500">{{ $item->descricao }}</p>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                @endforeach
            </div>
        </section>
    @endif

    {{-- Formulário de orçamento (requisito F.3) --}}
    <section id="orcamento" class="mb-12 rounded-md border border-slate-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-slate-900">Agende uma consulta / Peça um orçamento</h2>
        <p class="mb-4 text-sm text-slate-500">Deixa os teus dados e entramos em contacto contigo.</p>

        @if (session('sucesso'))
            <div class="mb-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                @foreach ($errors->all() as $erro)
                    <p>{{ $erro }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('atelier.landing.solicitar-orcamento', $empresa) }}" class="space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Nome *</label>
                    <input type="text" name="nome" required maxlength="255" value="{{ old('nome') }}" class="w-full rounded-md border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Telefone ou e-mail *</label>
                    <input type="text" name="contacto" required maxlength="255" value="{{ old('contacto') }}" class="w-full rounded-md border-slate-300 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">O que procuras?</label>
                <select name="categoria_interesse" class="w-full rounded-md border-slate-300 text-sm">
                    <option value="">Seleciona...</option>
                    @foreach ($categorias as $rotulo)
                        <option value="{{ $rotulo }}" @selected(old('categoria_interesse') === $rotulo)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Mensagem</label>
                <textarea name="mensagem" rows="3" maxlength="2000" class="w-full rounded-md border-slate-300 text-sm">{{ old('mensagem') }}</textarea>
            </div>

            {{-- Honeypot: invisível para pessoas, preenchido por robôs. --}}
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <label>Não preencher <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            {{-- Consentimento para tratamento de dados (Lei n.º 22/11). --}}
            <label class="flex items-start gap-2 text-xs text-slate-600">
                <input type="checkbox" name="consentimento" value="1" required class="mt-0.5 rounded border-slate-300"
                       @checked(old('consentimento'))>
                <span>
                    Aceito que {{ $empresa->nome_comercial }} trate os meus dados (nome e contacto) apenas para
                    responder a este pedido. Posso pedir o acesso, a correção ou a eliminação dos meus dados
                    a qualquer momento, contactando o atelier.
                </span>
            </label>

            <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Enviar pedido
            </button>
        </form>
    </section>

    {{-- Redes sociais --}}
    @if (! empty(array_filter($perfil->redes_sociais ?? [])))
        <footer class="flex justify-center gap-6 text-sm text-slate-500">
            @foreach (array_filter($perfil->redes_sociais) as $rede => $valor)
                <span>{{ ucfirst($rede) }}: {{ $valor }}</span>
            @endforeach
        </footer>
    @endif

    {{-- Alpine.js só é necessário para o filtro do portfólio; o layout público não o
         carrega por omissão (ao contrário de core::layouts.app), por isso vem aqui.
         Versão fixada (nunca @3.x.x) para não correr código novo sem revisão. --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js" defer></script>
@endsection
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# PATCHES PONTUAIS (views e testes existentes)
# -----------------------------------------------------------------------------
echo "A ajustar views e testes existentes..."

# Campanhas: permitir reenviar os envios que falharam.
VISTA_CAMPANHA='Modules/Atelier/resources/views/campanhas/mostrar.blade.php'
perl -0pi -e 's/\@if \(\$campanha->estado !== \x27enviada\x27\)/\@if (\$campanha->estado !== \x27enviada\x27 || \$campanha->envios->contains(\x27estado\x27, \x27falhou\x27))/' "$VISTA_CAMPANHA"
perl -0pi -e 's/^(\s{20})Enviar agora$/$1\{\{ \$campanha->estado === \x27enviada\x27 ? \x27Reenviar falhados\x27 : \x27Enviar agora\x27 \}\}/m' "$VISTA_CAMPANHA"
grep -q "Reenviar falhados" "$VISTA_CAMPANHA" && echo "  ok  $VISTA_CAMPANHA" || echo "  AVISO: não consegui ajustar $VISTA_CAMPANHA (aplica à mão: botão para reenviar falhados)"

# Pedido: ligação para o consentimento de marketing do cliente.
VISTA_PEDIDO='Modules/Atelier/resources/views/pedidos/mostrar.blade.php'
perl -0pi -e 's/(            <p class="text-sm text-slate-500">\{\{ \$pedido->tipoServicoRotulo\(\) \}\}<\/p>\n)/$1            \@if (\$podeVerFinancas && \$pedido->cliente)\n                <a href="\{\{ route(\x27atelier.crm.editar\x27, \$pedido->cliente->id) \}\}" class="text-xs text-indigo-600">Marketing e consentimento do cliente →<\/a>\n            \@endif\n/' "$VISTA_PEDIDO"
grep -q "atelier.crm.editar" "$VISTA_PEDIDO" && echo "  ok  $VISTA_PEDIDO" || echo "  AVISO: não consegui ajustar $VISTA_PEDIDO (adiciona à mão um link para route('atelier.crm.editar', \$pedido->cliente->id))"

# Testes existentes: a entrega/cancelamento passam a validar faturação por omissão.
TESTE_FLUXO='Modules/Atelier/tests/Feature/PedidoFluxoTest.php'
perl -pi -e 's/avancarPara\(\$pedido, \$estado\)/avancarPara(\$pedido, \$estado, null, false)/; s/avancarPara\(\$instanciaObsoleta, \x27entregue\x27\)/avancarPara(\$instanciaObsoleta, \x27entregue\x27, null, false)/' "$TESTE_FLUXO"
grep -q "null, false" "$TESTE_FLUXO" && echo "  ok  $TESTE_FLUXO" || echo "  AVISO: não consegui ajustar $TESTE_FLUXO"

# Testes existentes: o formulário público agora exige consentimento.
TESTE_LANDING='Modules/Atelier/tests/Feature/NotificacoesELandingTest.php'
perl -0pi -e 's/(\x27contacto\x27 => \x27900000000\x27,\n)/$1            \x27consentimento\x27 => \x271\x27,\n/g' "$TESTE_LANDING"
grep -q "consentimento" "$TESTE_LANDING" && echo "  ok  $TESTE_LANDING" || echo "  AVISO: não consegui ajustar $TESTE_LANDING"

# -----------------------------------------------------------------------------
# TESTES NOVOS
# -----------------------------------------------------------------------------
escrever 'Modules/Atelier/tests/Feature/CorrecoesAuditoriaTest.php' <<'__ATELIER_EOF__'
<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\PedidoStatusService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\Dinheiro;
use Tests\TestCase;

/**
 * Regressões das correções da auditoria ao módulo Atelier: regras
 * financeiras do estado do pedido, total do IVA coerente com a fatura,
 * consentimento de marketing (Lei n.º 22/11) e expiração dos links do portal.
 */
class CorrecoesAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function criarEmpresa(string $nif): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);

        return $empresa;
    }

    protected function criarPedido(string $nif, array $extra = []): Pedido
    {
        $empresa = $this->criarEmpresa($nif);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create(array_merge([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de teste',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
        ], $extra));
    }

    public function test_entrega_exige_a_fatura_final_emitida(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000201', ['status' => 'pronto_para_retirada']);

        try {
            app(PedidoStatusService::class)->avancarPara($pedido, 'entregue');
            $this->fail('A entrega sem fatura final emitida devia ser recusada.');
        } catch (PedidoException $e) {
            $this->assertStringContainsString('fatura', $e->getMessage());
        }

        $this->assertSame('pronto_para_retirada', $pedido->fresh()->status);
        Event::assertNotDispatched(PedidoMudouEstado::class);
    }

    public function test_cancelar_pedido_sem_fatura_continua_permitido(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000202');

        app(PedidoStatusService::class)->avancarPara($pedido, 'cancelado', 'Cliente desistiu');

        $this->assertSame('cancelado', $pedido->fresh()->status);
        Event::assertDispatched(PedidoMudouEstado::class);
    }

    public function test_total_do_pedido_usa_o_mesmo_arredondamento_por_linha_da_fatura(): void
    {
        $pedido = $this->criarPedido('400000203', ['valor_orcamento' => '100.01']);

        $esperado = Dinheiro::centimos(Dinheiro::calcularLinha(1, '100.01', '14.00')['total']);

        $this->assertSame($esperado, $pedido->fresh()->totalComIvaEmCentimos());
        $this->assertEqualsWithDelta($esperado / 100, $pedido->fresh()->valorTotalComIva(), 0.0001);
    }

    public function test_marketing_so_chega_a_clientes_com_consentimento_registado(): void
    {
        $empresa = $this->criarEmpresa('400000204');

        $semCrm = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem CRM']);
        $consentido = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Consentido']);
        $semData = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem data de consentimento']);
        $revogado = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Revogado']);

        ClienteCrm::create([
            'cliente_id' => $consentido->id,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
            'consentimento_origem' => 'ficha em papel',
        ]);
        ClienteCrm::create(['cliente_id' => $semData->id, 'aceita_marketing' => true]);
        ClienteCrm::create([
            'cliente_id' => $revogado->id,
            'aceita_marketing' => true,
            'consentimento_em' => now()->subDay(),
            'consentimento_revogado_em' => now(),
        ]);

        $campanha = (new Campanha)->forceFill(['empresa_id' => $empresa->id, 'segmento' => 'todos']);

        $ids = app(CrmService::class)->resolverSegmento($campanha)->pluck('id')->all();

        $this->assertSame([$consentido->id], $ids);
        $this->assertNotContains($semCrm->id, $ids);
    }

    public function test_mensagem_de_aniversario_so_para_quem_consentiu(): void
    {
        $empresa = $this->criarEmpresa('400000205');
        $nascimento = now()->subYears(30)->format('Y-m-d');

        $consentido = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Consentido']);
        $semConsentimento = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem consentimento']);

        ClienteCrm::create([
            'cliente_id' => $consentido->id,
            'data_nascimento' => $nascimento,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
        ]);
        ClienteCrm::create([
            'cliente_id' => $semConsentimento->id,
            'data_nascimento' => $nascimento,
            'aceita_marketing' => true,
        ]);

        $ids = app(CrmService::class)->aniversariantesHoje($empresa->id)->pluck('id')->all();

        $this->assertSame([$consentido->id], $ids);
    }

    public function test_link_do_portal_nunca_expira_depois_do_link_com_que_se_chegou(): void
    {
        config(['atelier.portal_link_validade_dias' => 7]);
        $pedido = $this->criarPedido('400000206');

        $limite = now()->addDay()->getTimestamp();
        request()->query->set('expires', (string) $limite);

        try {
            $link = app(PortalLinkService::class)->gerarLinkAcesso($pedido->cliente);
        } finally {
            request()->query->remove('expires');
        }

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame($limite, (int) $query['expires']);
    }

    public function test_formulario_publico_exige_consentimento_e_guarda_a_prova_dele(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Consentimento', 'nif' => '400000207']);
        $empresa->aderirServicos(['atelier']);
        $empresa->forceFill(['slug' => 'atelier-consentimento'])->save();
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => true]);

        $url = route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-consentimento']);

        $this->post($url, ['nome' => 'Visitante', 'contacto' => '900000000'])
            ->assertSessionHasErrors('consentimento');
        $this->assertSame(0, PedidoOrcamento::withoutGlobalScopes()->count());

        $this->post($url, ['nome' => 'Visitante', 'contacto' => '900000000', 'consentimento' => '1'])
            ->assertSessionHasNoErrors();

        $lead = PedidoOrcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->first();
        $this->assertNotNull($lead?->consentimento_em);
    }
}
__ATELIER_EOF__

# -----------------------------------------------------------------------------
# README: registo das alterações e do que fica por fazer
# -----------------------------------------------------------------------------
cat >> Modules/Atelier/README.md <<'__ATELIER_EOF__'


## Auditoria técnica e de conformidade (outubro de 2026)

Alterações feitas:

- **Campanhas**: todas as ações exigem Administrador; lock por campanha (clique duplo não duplica envios); campanhas já enviadas podem reenviar só os envios que falharam; o job ignora empresas sem acesso ativo/serviço atelier.
- **Aniversários**: só empresas ativas com o serviço atelier; no máximo uma mensagem por cliente por dia.
- **Estado do pedido**: o evento `PedidoMudouEstado` é disparado só depois do commit; a entrega exige fatura final emitida (e saldo pago, quando houver); cancelar com fatura emitida é recusado (exige nota de crédito no módulo Faturação); cancelar com sinal recebido avisa para regularizar a devolução/retenção.
- **Total do pedido**: `Pedido::totalComIvaEmCentimos()` usa `Dinheiro::calcularLinha` linha a linha, igual à fatura.
- **Autorização**: a Costureira só vê/avança os pedidos que lhe estão atribuídos; lista da equipa só para Administrador; leads de orçamento só para Administrador/Secretária.
- **Portal**: `throttle` nas rotas; links gerados a partir de um link aberto nunca expiram depois dele; só reagenda provas futuras em estado reagendável; exige empresa ativa com o serviço atelier.
- **Proteção de dados (Lei n.º 22/11)**: marketing passa a ser opt-in com prova (`consentimento_em`, `consentimento_origem`, revogação); ecrã para registar o consentimento (`atelier.crm.editar`); link de cancelamento em todas as mensagens de marketing; formulário público com aviso, consentimento e honeypot; fotos de pedidos em disco privado servidas por rota autenticada; `atelier_pedidos.empresa_id` deixa de apagar em cascata.

Por fazer (precisa de decisão ou de alterações no módulo Faturacao):

- Ligar o recibo do sinal à fatura final no SAF-T (AO) e rever o tratamento de IVA do adiantamento com o contabilista/AGT.
- Fluxo de nota de crédito/devolução do sinal ao cancelar pedidos já faturados.
- Exportação/eliminação de dados de um cliente a pedido (respeitando a retenção fiscal).
- Confirmar com a APD as obrigações de notificação do tratamento de dados.
__ATELIER_EOF__
echo "  ok  Modules/Atelier/README.md (secção adicionada)"

echo
echo "Concluído. Próximos passos:"
echo "  1) git diff                 # rever as alterações"
echo "  2) php artisan migrate      # aplica as 2 migrações novas (consentimento/retenção e fotos privadas)"
echo "  3) php artisan test         # corre os testes (incluindo CorrecoesAuditoriaTest)"
echo "  4) Registar o consentimento dos clientes em: Pedido > 'Marketing e consentimento do cliente'"
echo "     (até lá, nenhuma campanha ou mensagem de aniversário é enviada)."
