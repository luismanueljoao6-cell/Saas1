#!/usr/bin/env bash
# =============================================================================
# atelier-auditoria.sh — correções da auditoria ao módulo Atelier (saas1)
#
# Uso (na raiz do repositório, ou em qualquer pasta dentro dele):
#   bash atelier-auditoria.sh --simular    só verifica, não escreve nada
#   bash atelier-auditoria.sh              aplica num ramo novo (fix/atelier-auditoria)
#   bash atelier-auditoria.sh --forcar     ignora árvore suja e SHAs diferentes
#   bash atelier-auditoria.sh --sem-lint   não corre php -l no fim
#
# Segurança: recusa correr com alterações por commitar em Modules/Atelier; só
# reescreve ficheiros cujo SHA seja o da versão auditada (main); trabalha num
# ramo próprio e faz commit. Desfazer = voltar ao ramo anterior e apagar este.
# NÃO corre migrate nem testes: ver "Próximos passos" no fim.
# Se aparecerem erros com \r:  sed -i 's/\r$//' atelier-auditoria.sh
# =============================================================================
set -uo pipefail

FORCAR=0; SIMULAR=0; SEM_LINT=0
for arg in "$@"; do
  case "$arg" in
    --forcar) FORCAR=1 ;;
    --simular) SIMULAR=1 ;;
    --sem-lint) SEM_LINT=1 ;;
    -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
    *) echo "Opção desconhecida: $arg"; exit 2 ;;
  esac
done

AVISOS=(); ESCRITOS=(); FALHAS=0
info()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
aviso() { printf '  \033[33m!\033[0m %s\n' "$*"; AVISOS+=("$*"); }
erro()  { printf '\033[31mErro:\033[0m %s\n' "$*" >&2; }

RAIZ=$(git rev-parse --show-toplevel 2>/dev/null) || { erro "Corre isto dentro do repositório git do saas1."; exit 1; }
cd "$RAIZ" || exit 1
[ -d Modules/Atelier ] || { erro "Não encontrei Modules/Atelier em $RAIZ."; exit 1; }
command -v perl >/dev/null 2>&1 || { erro "Preciso do perl (vem com o Git Bash e com o WSL)."; exit 1; }

# ---------------------------------------------------------------- helpers ----
# escrever <caminho>   (conteúdo completo do ficheiro via stdin)
escrever() {
  local alvo="$1"
  if [ "$SIMULAR" -eq 1 ]; then cat >/dev/null; ok "(simulação) $alvo"; return 0; fi
  mkdir -p "$(dirname "$alvo")"
  cat > "$alvo"
  ESCRITOS+=("$alvo")
  ok "$alvo"
}

# subst <ficheiro> <n.º esperado de ocorrências> <texto antigo> <texto novo>
# Substituição LITERAL; se o número de ocorrências não for o esperado, não altera nada.
subst() {
  local f="$1" n="$2" old="$3" new="$4" c
  if [ "$SIMULAR" -eq 1 ]; then ok "(simulação) $f"; return 0; fi
  if [ ! -f "$f" ]; then aviso "Em falta: $f"; FALHAS=$((FALHAS+1)); return 1; fi
  c=$(OLD="$old" perl -0777 -ne 'BEGIN{$o=$ENV{OLD}} $c=()=/\Q$o\E/g; print $c' "$f")
  if [ "$c" != "$n" ]; then
    aviso "$f: esperava $n ocorrência(s) de «${old:0:60}», encontrei ${c:-0} — NÃO alterado"
    FALHAS=$((FALHAS+1)); return 1
  fi
  OLD="$old" NEW="$new" perl -0777 -i.bak -pe 'BEGIN{$o=$ENV{OLD};$n=$ENV{NEW}} s/\Q$o\E/$n/g' "$f" && rm -f "$f.bak"
  ESCRITOS+=("$f"); ok "$f"
}

# ------------------------------------------------- 1. verificações prévias ----
info "A verificar o repositório…"
PATHS_ESTADO=(Modules/Atelier); [ -f .env.example ] && PATHS_ESTADO+=(.env.example)
if [ -n "$(git status --porcelain -- "${PATHS_ESTADO[@]}" 2>/dev/null)" ]; then
  if [ "$FORCAR" -eq 1 ]; then aviso "Há alterações por commitar em Modules/Atelier (continuo por causa de --forcar)."
  else
    erro "Há alterações por commitar em Modules/Atelier. Faz commit/stash primeiro (ou usa --forcar):"
    git status --short -- "${PATHS_ESTADO[@]}" | head -20; exit 1
  fi
fi

# SHAs (blob git) da versão do GitHub (main) que foi auditada.
SHAS='0014b355749ca46eceaa8872cf9bdb7b491bfd42 Modules/Atelier/app/Models/Pedido.php
4da88268653cef3ad497a57f1422db19e4688f56 Modules/Atelier/app/Models/PedidoMaterial.php
f356111668ca4341133ab6977ce2134eb8cbd177 Modules/Atelier/app/Models/ClienteCrm.php
4413eb1dee23b3bc972044d7e24436955c938ee5 Modules/Atelier/app/Exceptions/PedidoException.php
0dff1a42b658342b65521cff94bb2abc75adc9e8 Modules/Atelier/app/Exceptions/NotificacaoException.php
5dc4450e53878127ad2e9aaa600e28e90f150c2f Modules/Atelier/app/Services/PedidoFaturacaoService.php
1b3657f50d84c613118b00b202404999fe9bc0ac Modules/Atelier/app/Services/PedidoStatusService.php
cd837bd95af863f18c25b72ae0af2f2575c12ae8 Modules/Atelier/app/Services/CrmService.php
93452a91cf80214ae00d5fef17507dc0bf363ffe Modules/Atelier/app/Services/Notificacoes/NotificadorClienteService.php
d922b6eb2724ce9e499f23d668f15fd20754a384 Modules/Atelier/app/Services/Notificacoes/WhatsAppCanal.php
8502b9fccddb524ed2dec3d85fed258fc24eb10e Modules/Atelier/app/Services/Notificacoes/SmsCanal.php
60e52aeaa2a39c60c5c123a56dc681aef58c36c0 Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
7576a1dabc6c63e7409cdf23aad5b0eabfc71eb3 Modules/Atelier/app/Http/Requests/AvancarEstadoPedidoRequest.php
bf3535733f439c83bff4939b0baf98cbb96ee2d5 Modules/Atelier/app/Http/Requests/GuardarPedidoRequest.php
9bf0920013343e29ca1c71ee14c7e933780f2c5a Modules/Atelier/app/Jobs/LembretesProvaJob.php
2622caf8a2256c8e2752d234a0293dc2e88c9e77 Modules/Atelier/app/Jobs/VerificarAniversariosJob.php
59809a12a0b4b7c0742e83e9f4e8e6bad44ea0cb Modules/Atelier/app/Jobs/DispararCampanhaJob.php
bafac4fccb6168ba74cf0f08b71c2fb57f9a9ec9 Modules/Atelier/app/Jobs/EnviarCampanhasAgendadasJob.php'

info "A confirmar que os ficheiros são a versão que auditei…"
DIFERENTES=()
while read -r sha caminho; do
  [ -z "$sha" ] && continue
  if [ ! -f "$caminho" ]; then DIFERENTES+=("$caminho (não existe)"); continue; fi
  [ "$(git hash-object -- "$caminho" 2>/dev/null)" = "$sha" ] || DIFERENTES+=("$caminho")
done <<< "$SHAS"
if [ "${#DIFERENTES[@]}" -gt 0 ]; then
  if [ "$FORCAR" -eq 1 ]; then aviso "${#DIFERENTES[@]} ficheiro(s) diferem da versão auditada — vão ser reescritos na mesma (--forcar)."
  else
    erro "Estes ficheiros mudaram desde a auditoria (reescrevê-los apagaria alterações tuas):"
    printf '    %s\n' "${DIFERENTES[@]}"
    echo "Faz git pull / manda-me o diff, ou usa --forcar se tiveres a certeza."; exit 1
  fi
else ok "todos coincidem com a versão auditada"; fi

# --------------------------------------------------------------- 2. ramo ----
RAMO="fix/atelier-auditoria"; RAMO_ANTERIOR=$(git rev-parse --abbrev-ref HEAD)
git show-ref --verify --quiet "refs/heads/$RAMO" && RAMO="$RAMO-$(date +%Y%m%d%H%M)"
if [ "$SIMULAR" -eq 0 ]; then
  git checkout -b "$RAMO" >/dev/null 2>&1 || { erro "Não consegui criar o ramo $RAMO."; exit 1; }
  ok "ramo $RAMO (vindo de $RAMO_ANTERIOR)"
else info "Simulação: nada será escrito."; fi

info "A escrever ficheiros…"

# ---- novos: suporte, pedidos de formulário, CRM -----------------------------
escrever Modules/Atelier/app/Support/TelefoneAngola.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Support;

/**
 * Normaliza telefones para o formato internacional só com dígitos
 * (ex.: 244923456789), que é o que a Z-API e a maioria dos gateways de SMS
 * esperam. Aceita "923 456 789", "+244 923-456-789", "00244923456789"…
 *
 * Um número de 9 dígitos a começar por 9 é tratado como móvel nacional
 * (Angola) e recebe o código do país. Devolve null se não parecer válido.
 */
final class TelefoneAngola
{
    public static function normalizar(?string $bruto, ?string $codigoPais = null): ?string
    {
        $codigoPais ??= (string) config('atelier.telefone_codigo_pais', '244');

        $digitos = preg_replace('/\D+/', '', (string) $bruto) ?? '';

        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        }

        $tamanho = strlen($digitos);

        if ($tamanho === 9) {
            return $digitos[0] === '9' ? $codigoPais.$digitos : null;
        }

        if ($tamanho < 10 || $tamanho > 15 || $digitos[0] === '0') {
            return null;
        }

        if (str_starts_with($digitos, $codigoPais) && $tamanho !== strlen($codigoPais) + 9) {
            return null;
        }

        return $digitos;
    }

    /** Esconde o meio do número para os logs (24492****89). */
    public static function mascarar(string $numero): string
    {
        return strlen($numero) <= 6 ? '****' : substr($numero, 0, 5).'****'.substr($numero, -2);
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Support/ElegibilidadeAutomacao.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Support;

use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;

/**
 * Os Jobs do Atelier correm para todas as empresas (dentro do bypass do
 * TenantManager). Esta regra impede que gastem mensagens (e dinheiro da
 * plataforma) com empresas que não contrataram o Atelier ou cuja
 * subscrição já não dá acesso.
 */
final class ElegibilidadeAutomacao
{
    public static function empresaPodeEnviar(Empresa $empresa): bool
    {
        return $empresa->temServico(Servico::Atelier) && $empresa->temAcesso();
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Http/Requests/EnviarCampanhaRequest.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Só o Administrador dispara envios em massa (mesma regra de GuardarCampanhaRequest). */
class EnviarCampanhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Http/Requests/AtualizarClienteCrmRequest.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarClienteCrmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'data_nascimento' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'aceita_marketing' => ['sometimes', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Http/Controllers/Admin/ClienteCrmController.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\AtualizarClienteCrmRequest;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Faturacao\Models\Cliente;

/**
 * Único sítio onde se regista a data de nascimento e o CONSENTIMENTO de
 * marketing do cliente (ClienteCrm). Sem isto, os aniversários automáticos
 * e as campanhas (com consentimento explícito) não têm a quem enviar.
 *
 * {cliente} chega como int, nunca tipado: o binding implícito corre antes
 * do middleware 'tenant' (mesmo padrão dos restantes controllers).
 */
class ClienteCrmController extends Controller
{
    public function editar(int $cliente): View
    {
        abort_unless(request()->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);

        $cliente = Cliente::findOrFail($cliente);

        return view('atelier::crm.editar', [
            'cliente' => $cliente,
            'crm' => ClienteCrm::firstOrNew(['cliente_id' => $cliente->id]),
        ]);
    }

    public function atualizar(AtualizarClienteCrmRequest $request, int $cliente): RedirectResponse
    {
        $cliente = Cliente::findOrFail($cliente);
        $crm = ClienteCrm::firstOrNew(['cliente_id' => $cliente->id]);
        $aceita = $request->boolean('aceita_marketing');

        if ($aceita && ! $crm->aceita_marketing) {
            $crm->consentimento_registado_em = now();
        } elseif (! $aceita) {
            $crm->consentimento_registado_em = null;
        }

        $crm->data_nascimento = $request->validated('data_nascimento');
        $crm->aceita_marketing = $aceita;
        $crm->notas = $request->validated('notas');
        $crm->save();

        return redirect()->route('atelier.crm.editar', $cliente->id)
            ->with('sucesso', 'Dados do cliente guardados.');
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/resources/views/crm/editar.blade.php <<'__ATELIER_FIM__'
@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Dados do cliente (CRM)')

@section('conteudo')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">{{ $cliente->nome }}</h1>
        <p class="text-sm text-slate-500">Aniversário e consentimento para receber mensagens de marketing.</p>
    </div>

    <form method="POST" action="{{ route('atelier.crm.atualizar', $cliente->id) }}"
          class="max-w-xl space-y-4 rounded-md border border-slate-200 bg-white p-5">
        @csrf
        @method('PUT')

        <div>
            <label for="data_nascimento" class="mb-1 block text-xs font-medium text-slate-600">Data de nascimento</label>
            <input type="date" id="data_nascimento" name="data_nascimento"
                   value="{{ old('data_nascimento', $crm->data_nascimento?->format('Y-m-d')) }}"
                   class="w-full rounded-md border-slate-300 text-sm">
            @error('data_nascimento')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-start gap-2">
            <input type="checkbox" id="aceita_marketing" name="aceita_marketing" value="1"
                   class="mt-1 rounded border-slate-300" @checked(old('aceita_marketing', $crm->aceita_marketing ?? false))>
            <label for="aceita_marketing" class="text-sm text-slate-700">
                O cliente aceita receber mensagens de marketing (aniversário, promoções, campanhas).
                @if ($crm->consentimento_registado_em)
                    <span class="block text-xs text-slate-400">Consentimento registado em {{ $crm->consentimento_registado_em->format('d/m/Y H:i') }}</span>
                @endif
            </label>
        </div>

        <div>
            <label for="notas" class="mb-1 block text-xs font-medium text-slate-600">Notas</label>
            <textarea id="notas" name="notas" rows="3" class="w-full rounded-md border-slate-300 text-sm">{{ old('notas', $crm->notas) }}</textarea>
            @error('notas')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Guardar
        </button>
    </form>
@endsection
__ATELIER_FIM__

# ---- migrations -------------------------------------------------------------
escrever Modules/Atelier/database/migrations/2026_10_26_000001_add_iniciada_em_to_atelier_campanhas_table.php <<'__ATELIER_FIM__'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Reivindicação" atómica de uma campanha: DispararCampanhaJob só avança se
 * conseguir passar iniciada_em de NULL para agora numa única UPDATE. Evita o
 * duplo envio (duplo clique, job horário a re-despachar, retries da fila)
 * sem mexer no ENUM de `estado`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('atelier_campanhas', 'iniciada_em')) {
            return;
        }

        Schema::table('atelier_campanhas', function (Blueprint $table) {
            $table->timestamp('iniciada_em')->nullable()->after('enviada_em');
        });
    }

    public function down(): void
    {
        Schema::table('atelier_campanhas', function (Blueprint $table) {
            $table->dropColumn('iniciada_em');
        });
    }
};
__ATELIER_FIM__

escrever Modules/Atelier/database/migrations/2026_10_26_000002_add_consentimento_e_aniversario_to_atelier_clientes_crm_table.php <<'__ATELIER_FIM__'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            if (! Schema::hasColumn('atelier_clientes_crm', 'aniversario_enviado_ano')) {
                // Ano em que a mensagem de aniversário já foi enviada — impede duplicados.
                $table->unsignedSmallInteger('aniversario_enviado_ano')->nullable()->after('aceita_marketing');
            }

            if (! Schema::hasColumn('atelier_clientes_crm', 'consentimento_registado_em')) {
                // Quando o consentimento de marketing foi registado (prova de consentimento).
                $table->timestamp('consentimento_registado_em')->nullable()->after('aniversario_enviado_ano');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            $table->dropColumn(['aniversario_enviado_ano', 'consentimento_registado_em']);
        });
    }
};
__ATELIER_FIM__

# ---- exceções e modelos -----------------------------------------------------
escrever Modules/Atelier/app/Exceptions/PedidoException.php <<'__ATELIER_FIM__'
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

    public static function pedidoCancelado(): self
    {
        return new self('Este pedido foi cancelado — não é possível registar documentos financeiros nele.');
    }

    public static function pedidoJaEntregue(): self
    {
        return new self('Este pedido já foi entregue — não é possível registar um sinal.');
    }

    public static function sinalNaoSuportadoComAgt(): self
    {
        return new self('Com a comunicação AGT ativa não é possível registar sinais (recibos de adiantamento). Gera e emite a fatura final e regista o pagamento do valor total.');
    }

    public static function semSaldoPorReceber(): self
    {
        return new self('Não há saldo por receber neste pedido — o sinal já cobre o total da fatura.');
    }

    public static function pagamentoPendente(): self
    {
        return new self('Este pedido ainda tem saldo por pagar — regista o pagamento antes de o marcar como entregue.');
    }

    public static function isencaoSemMotivo(): self
    {
        return new self('Este pedido tem IVA a 0% e falta o motivo de isenção (variável ATELIER_MOTIVO_ISENCAO) — configura-o antes de gerar a fatura.');
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Exceptions/NotificacaoException.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Exceptions;

use Exception;

class NotificacaoException extends Exception
{
    public static function falhaNoEnvio(string $canal, string $motivo): self
    {
        return new self("Falha ao enviar notificação por {$canal}: {$motivo}");
    }

    public static function naoConfigurado(string $canal): self
    {
        return new self("O canal \"{$canal}\" não está configurado (faltam credenciais no .env) — ver README do Atelier.");
    }

    public static function contactoEmFalta(string $canal): self
    {
        return new self("O cliente não tem contacto guardado para o canal \"{$canal}\".");
    }

    public static function numeroInvalido(string $canal): self
    {
        return new self("O número de telefone do cliente não é válido para o canal \"{$canal}\".");
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Models/ClienteCrm.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

/**
 * Extensão de CRM sobre Modules\Faturacao\Models\Cliente (ver decisão de
 * arquitetura no README: guardado aqui para não alterar o schema do módulo
 * Faturacao). Criado sob demanda — nem todo cliente tem uma linha aqui; a
 * ficha de CRM (ClienteCrmController) é onde se regista o consentimento.
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
        'aniversario_enviado_ano',
        'consentimento_registado_em',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'aceita_marketing' => 'boolean',
            'consentimento_registado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Models/PedidoMaterial.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Faturacao\Support\Dinheiro;

class PedidoMaterial extends Model
{
    protected $table = 'atelier_pedido_materiais';

    protected $fillable = ['pedido_id', 'descricao', 'quantidade', 'valor_unitario', 'valor_total', 'ordem'];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /** Aritmética exata (sem float), a mesma da linha de fatura: quantidade × preço, half-up ao cêntimo. */
    public function calcularValores(): static
    {
        $this->valor_total = Dinheiro::calcularLinha($this->quantidade, $this->valor_unitario, 0)['sem_iva'];

        return $this;
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Models/Pedido.php <<'__ATELIER_FIM__'
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
     * Total com IVA em CÊNTIMOS, calculado linha a linha com a mesma
     * aritmética da fatura final (Dinheiro::calcularLinha: IVA arredondado
     * por excesso, como exige a AGT). Assim o total do pedido coincide
     * sempre, ao cêntimo, com o da fatura que PedidoFaturacaoService gera
     * (uma linha para a peça + uma por material).
     */
    public function totalComIvaCentimos(): int
    {
        $taxa = (string) ($this->taxa_iva_aplicada ?? 0);

        $linhas = [[1, (string) ($this->valor_orcamento ?? 0)]];

        foreach ($this->materiais as $material) {
            $linhas[] = [(string) $material->quantidade, (string) $material->valor_unitario];
        }

        $total = 0;

        foreach ($linhas as [$quantidade, $preco]) {
            $total += Dinheiro::centimos(Dinheiro::calcularLinha($quantidade, $preco, $taxa)['total']);
        }

        return $total;
    }

    public function valorTotalComIva(): float
    {
        return $this->totalComIvaCentimos() / 100;
    }

    /**
     * Saldo em falta para a entrega, depois de descontado o sinal já pago
     * (nunca negativo). Usado na view do pedido e nas notificações.
     */
    public function saldoEmFalta(): float
    {
        $saldo = $this->totalComIvaCentimos() - Dinheiro::centimos($this->valor_sinal ?? 0);

        return max(0, $saldo) / 100;
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
__ATELIER_FIM__

# ---- serviços: faturação, estado, CRM, notificações --------------------------
escrever Modules/Atelier/app/Services/PedidoFaturacaoService.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 *   fiscal AGT, não entra na cadeia de numeração/hash). Com a comunicação
 *   AGT ativa o ReciboService não suporta adiantamentos — daí a recusa
 *   antecipada e com mensagem clara em registarSinal().
 * - A fatura final é sempre criada como RASCUNHO; a emissão fica no ecrã
 *   normal de faturas do módulo Faturacao.
 * - Toda a emissão de Recibos passa pelo ReciboService (caminho único).
 * - Cada operação relê o Pedido com lock: um duplo clique não emite dois
 *   recibos nem gera duas faturas finais.
 * - Nenhum documento financeiro é registado num pedido cancelado.
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
     * @param  string|int|float  $valor  em unidades monetárias (ex.: '3000.00')
     *
     * @throws PedidoException|Throwable
     */
    public function registarSinal(Pedido $pedido, string|int|float $valor, string $meioPagamento): Recibo
    {
        return DB::transaction(function () use ($pedido, $valor, $meioPagamento) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw PedidoException::pedidoCancelado();
            }

            if ($pedido->status === 'entregue') {
                throw PedidoException::pedidoJaEntregue();
            }

            if ($pedido->jaTemSinalRegistado()) {
                throw PedidoException::sinalJaRegistado();
            }

            // O ReciboService recusa recibos de adiantamento com a AGT ativa:
            // falhar aqui dá a causa real em vez de um erro genérico.
            if (config('faturacao.agt.ativo')) {
                throw PedidoException::sinalNaoSuportadoComAgt();
            }

            try {
                $sinal = Dinheiro::centimos($valor);
            } catch (\InvalidArgumentException) {
                throw PedidoException::valorInvalido();
            }

            if ($sinal <= 0) {
                throw PedidoException::valorInvalido();
            }

            if ($sinal > $pedido->totalComIvaCentimos()) {
                throw new PedidoException('O sinal não pode exceder o valor total do pedido.');
            }

            $valorFormatado = Dinheiro::formatar($sinal);

            $recibo = $this->recibos()->emitir(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                $valorFormatado,
                $meioPagamento,
                null,
            );

            $pedido->update([
                'valor_sinal' => $valorFormatado,
                'recibo_sinal_id' => $recibo->id,
            ]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $recibo;
        });
    }

    /**
     * Rascunho da fatura final: uma linha para o serviço/peça (taxa guardada
     * no pedido, nunca a "atual" da config) e uma por material cobrado.
     * Com IVA a 0% o Faturacao exige motivo de isenção: vem de
     * config('atelier.motivo_isencao').
     *
     * @throws PedidoException
     */
    public function gerarFaturaFinalRascunho(Pedido $pedido, ?string $observacoes = null): Fatura
    {
        return DB::transaction(function () use ($pedido, $observacoes) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw PedidoException::pedidoCancelado();
            }

            if ($pedido->jaTemFaturaFinal()) {
                throw PedidoException::faturaFinalJaGerada();
            }

            $cliente = $pedido->cliente;

            if (! $cliente) {
                throw PedidoException::clienteSemDadosFaturacao();
            }

            $taxa = (float) $pedido->taxa_iva_aplicada;
            $motivoIsencao = null;

            if ($taxa <= 0) {
                $motivoIsencao = config('atelier.motivo_isencao') ?: null;

                if ($motivoIsencao === null) {
                    throw PedidoException::isencaoSemMotivo();
                }
            }

            $linhas = [
                $this->linha(
                    "{$pedido->tipoServicoRotulo()}: {$pedido->descricao}",
                    1,
                    (float) $pedido->valor_orcamento,
                    $taxa,
                    $motivoIsencao,
                ),
            ];

            foreach ($pedido->materiais as $material) {
                $linhas[] = $this->linha(
                    $material->descricao,
                    (float) $material->quantidade,
                    (float) $material->valor_unitario,
                    $taxa,
                    $motivoIsencao,
                );
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
                throw PedidoException::pedidoCancelado();
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
                throw PedidoException::semSaldoPorReceber();
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

    /** @return array<string, mixed> */
    protected function linha(string $descricao, float|int $quantidade, float $preco, float $taxa, ?string $motivoIsencao): array
    {
        $linha = [
            'descricao' => $this->descricaoDaLinha($descricao),
            'quantidade' => $quantidade,
            'preco_unitario' => $preco,
            'taxa_iva' => $taxa,
        ];

        if ($motivoIsencao !== null) {
            $linha['motivo_isencao'] = $motivoIsencao;
        }

        return $linha;
    }

    /** Uma só linha de texto com no máximo 200 caracteres (a descrição do pedido pode ter 2000). */
    protected function descricaoDaLinha(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        return Str::limit($texto, 197, '...');
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Services/PedidoStatusService.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;

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

    public function avancarPara(Pedido $pedido, string $novoEstado, ?string $motivoCancelamento = null): Pedido
    {
        return DB::transaction(function () use ($pedido, $novoEstado, $motivoCancelamento) {
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

            // Política opcional (ATELIER_EXIGIR_PAGAMENTO_PARA_ENTREGA, desligada
            // por omissão): só entrega depois de o saldo estar pago.
            if (
                $novoEstado === 'entregue'
                && config('atelier.exigir_pagamento_para_entrega')
                && ! $bloqueado->recibo_saldo_final_id
                && $bloqueado->saldoEmFalta() > 0
            ) {
                throw PedidoException::pagamentoPendente();
            }

            $bloqueado->status = $novoEstado;

            if ($novoEstado === 'cancelado') {
                $bloqueado->motivo_cancelamento = $motivoCancelamento;

                if ($bloqueado->jaTemSinalRegistado()) {
                    Log::warning('Pedido cancelado com sinal já recebido — a devolução ou regularização tem de ser tratada manualmente.', [
                        'pedido_id' => $bloqueado->id,
                        'empresa_id' => $bloqueado->empresa_id,
                        'recibo_sinal_id' => $bloqueado->recibo_sinal_id,
                    ]);
                }
            }

            $bloqueado->save();

            // Mantém a instância que o controller entregou sincronizada,
            // mas o evento usa os dados relidos e gravados sob lock.
            $pedido->setRawAttributes($bloqueado->getAttributes(), true);

            Log::info('Pedido mudou de estado', [
                'pedido_id' => $bloqueado->id,
                'empresa_id' => $bloqueado->empresa_id,
                'de' => $estadoAnterior,
                'para' => $novoEstado,
            ]);

            event(new PedidoMudouEstado($pedido, $estadoAnterior));

            return $pedido;
        });
    }

    /** @return array<int, string> Estados para os quais este pedido pode avançar agora — usado para desenhar os botões de ação na view. */
    public function proximosEstadosPossiveis(Pedido $pedido): array
    {
        return self::TRANSICOES_PERMITIDAS[$pedido->status] ?? [];
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Services/CrmService.php <<'__ATELIER_FIM__'
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
 * Consentimento: por omissão (atelier.marketing_exigir_opt_in) só recebem
 * marketing os clientes com ClienteCrm::aceita_marketing = true. Com a opção
 * desligada, recebem todos menos os que recusaram explicitamente.
 */
class CrmService
{
    public function aniversariantesHoje(int $empresaId): Collection
    {
        $hoje = now();
        $ano = (int) $hoje->year;

        $clienteIds = ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->where('aceita_marketing', true)
            ->whereNotNull('data_nascimento')
            ->where(function ($q) use ($ano) {
                $q->whereNull('aniversario_enviado_ano')->orWhere('aniversario_enviado_ano', '<', $ano);
            })
            ->where(function ($q) use ($hoje) {
                $q->where(function ($d) use ($hoje) {
                    $d->whereMonth('data_nascimento', $hoje->month)->whereDay('data_nascimento', $hoje->day);
                });

                // Quem nasceu a 29/02 festeja a 28/02 nos anos não bissextos.
                if (! $hoje->isLeapYear() && $hoje->month === 2 && $hoje->day === 28) {
                    $q->orWhere(function ($d) {
                        $d->whereMonth('data_nascimento', 2)->whereDay('data_nascimento', 29);
                    });
                }
            })
            ->pluck('cliente_id');

        return Cliente::whereIn('id', $clienteIds)->get();
    }

    /** Clientes com pelo menos um pedido, mas nenhum recente nem ainda em curso. */
    public function clientesInativos(int $empresaId, int $meses): Collection
    {
        $dataLimite = now()->subMonths($meses);

        $todosComPedido = Pedido::where('empresa_id', $empresaId)->pluck('cliente_id')->unique();

        // Um pedido ainda em curso conta como atividade, por antigo que seja.
        $comAtividade = Pedido::where('empresa_id', $empresaId)
            ->where(function ($q) use ($dataLimite) {
                $q->where('created_at', '>=', $dataLimite)
                    ->orWhereNotIn('status', (array) config('atelier.estados_finais'));
            })
            ->pluck('cliente_id')
            ->unique();

        $idsInativos = $todosComPedido->diff($comAtividade);

        return $this->filtrarOptIn(Cliente::whereIn('id', $idsInativos)->get());
    }

    public function resolverSegmento(Campanha $campanha): Collection
    {
        return match ($campanha->segmento) {
            'todos' => $this->filtrarOptIn(Cliente::where('empresa_id', $campanha->empresa_id)->get()),
            'aniversariantes_mes' => $this->filtrarOptIn($this->clientesComAniversarioNoMes($campanha->empresa_id)),
            'inativos' => $this->clientesInativos($campanha->empresa_id, (int) config('atelier.meses_inatividade_reativacao')),
            default => collect(),
        };
    }

    protected function clientesComAniversarioNoMes(int $empresaId): Collection
    {
        $clienteIds = ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->whereNotNull('data_nascimento')
            ->whereMonth('data_nascimento', now()->month)
            ->pluck('cliente_id');

        return Cliente::whereIn('id', $clienteIds)->get();
    }

    protected function filtrarOptIn(Collection $clientes): Collection
    {
        if (config('atelier.marketing_exigir_opt_in', true)) {
            $aceitam = ClienteCrm::whereIn('cliente_id', $clientes->pluck('id'))
                ->where('aceita_marketing', true)
                ->pluck('cliente_id');

            return $clientes->whereIn('id', $aceitam)->values();
        }

        $recusaram = ClienteCrm::whereIn('cliente_id', $clientes->pluck('id'))
            ->where('aceita_marketing', false)
            ->pluck('cliente_id');

        return $clientes->whereNotIn('id', $recusaram)->values();
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Services/Notificacoes/NotificadorClienteService.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Faturacao\Models\Cliente;
use Throwable;

/**
 * Ponto único de disparo de notificações ao cliente (requisito C). Percorre
 * os canais ativos em config('atelier.canais_notificacao') — por omissão só
 * 'mail', que funciona de imediato; 'whatsapp'/'sms' só fazem algo quando
 * as credenciais em .env estiverem preenchidas (ver WhatsAppCanal/SmsCanal).
 * Falha num canal nunca impede os restantes — cada envio é isolado e só
 * regista um warning.
 */
class NotificadorClienteService
{
    /**
     * @return bool true se pelo menos um canal aceitou a mensagem (o e-mail
     *              conta quando entra na fila); false se nada foi entregue —
     *              os Jobs usam isto para só marcarem "enviado" quando é verdade.
     */
    public function notificar(
        Cliente $cliente,
        string $assunto,
        string $mensagem,
        ?string $linkAcao = null,
        ?string $textoAcao = null,
    ): bool {
        $entregue = false;

        foreach ((array) config('atelier.canais_notificacao') as $canal) {
            $canal = trim((string) $canal);

            try {
                $resultado = match ($canal) {
                    'mail' => $this->enviarMail($cliente, $assunto, $mensagem, $linkAcao, $textoAcao),
                    'whatsapp' => $this->enviarViaCanal(app(WhatsAppCanal::class), $cliente, $mensagem),
                    'sms' => $this->enviarViaCanal(app(SmsCanal::class), $cliente, $mensagem),
                    '' => false,
                    default => $this->canalDesconhecido($canal),
                };

                $entregue = $entregue || $resultado;
            } catch (Throwable $e) {
                Log::warning('Atelier: falha ao notificar cliente por um canal', [
                    'canal' => $canal,
                    'cliente_id' => $cliente->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return $entregue;
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

        // notifyNow: a campanha já corre numa fila; enviar já (em vez de pôr
        // outra notificação na fila) faz com que "enviado" signifique mesmo
        // enviado, e uma falha do servidor de e-mail apareça como 'falhou'.
        Notification::route('mail', $cliente->email)
            ->notifyNow(new ClienteAtelierNotification($assunto, $mensagem));
    }

    protected function enviarOuFalhar(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): void
    {
        if (! $cliente->telefone) {
            throw NotificacaoException::contactoEmFalta($canal->identificador());
        }

        $canal->enviar($cliente->telefone, $mensagem);
    }

    protected function enviarMail(Cliente $cliente, string $assunto, string $mensagem, ?string $linkAcao, ?string $textoAcao): bool
    {
        if (! $cliente->email) {
            return false;
        }

        Notification::route('mail', $cliente->email)
            ->notify(new ClienteAtelierNotification($assunto, $mensagem, $linkAcao, $textoAcao));

        return true;
    }

    protected function enviarViaCanal(CanalNotificacaoInterface $canal, Cliente $cliente, string $mensagem): bool
    {
        if (! $cliente->telefone) {
            return false;
        }

        try {
            $canal->enviar($cliente->telefone, $mensagem);

            return true;
        } catch (NotificacaoException $e) {
            Log::warning('Atelier: falha ao notificar cliente', [
                'canal' => $canal->identificador(),
                'cliente_id' => $cliente->id,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function canalDesconhecido(string $canal): bool
    {
        Log::warning("Atelier: canal de notificação desconhecido \"{$canal}\".");

        return false;
    }
}
__ATELIER_FIM__

# ---- pedidos de formulário e canais de notificação --------------------------
escrever Modules/Atelier/app/Http/Requests/AvancarEstadoPedidoRequest.php <<'__ATELIER_FIM__'
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

            // Só mexe nos pedidos que lhe foram atribuídos (nunca nos das colegas).
            return Pedido::query()
                ->whereKey((int) $this->route('pedido'))
                ->where('responsavel_id', $user->id)
                ->exists();
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
__ATELIER_FIM__

escrever Modules/Atelier/app/Http/Requests/GuardarPedidoRequest.php <<'__ATELIER_FIM__'
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

        // before_or_equal:<outro campo> falha se o outro campo estiver vazio,
        // por isso a regra só entra quando há data prevista de entrega.
        $prazoInterno = ['nullable', 'date'];

        if ($this->filled('data_prevista_entrega')) {
            $prazoInterno[] = 'before_or_equal:data_prevista_entrega';
        }

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            // A medida tem de ser do MESMO cliente do pedido (e da mesma empresa).
            'medida_id' => [
                'nullable',
                'integer',
                Rule::exists('atelier_medidas', 'id')
                    ->where('empresa_id', $empresaId)
                    ->where('cliente_id', $this->input('cliente_id')),
            ],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $empresaId)],
            'tipo_servico' => ['required', 'string', 'in:confecao_medida,ajuste_conserto,restauracao,figurino'],
            'descricao' => ['required', 'string', 'max:2000'],
            'tecido_cor' => ['nullable', 'string', 'max:255'],
            'aviamentos_necessarios' => ['nullable', 'string', 'max:2000'],
            'data_prevista_entrega' => ['nullable', 'date', 'after_or_equal:today'],
            'prazo_interno' => $prazoInterno,
            // Limites abaixo das colunas decimal(14,2)/decimal(10,2): um valor
            // gigante dava erro 500 da base de dados em vez de erro de validação.
            'valor_orcamento' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'materiais' => ['nullable', 'array', 'max:50'],
            'materiais.*.descricao' => ['required_with:materiais', 'string', 'max:255'],
            'materiais.*.quantidade' => ['required_with:materiais', 'numeric', 'min:0.01', 'max:9999.99', 'decimal:0,2'],
            'materiais.*.valor_unitario' => ['required_with:materiais', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\EnviarCampanhaRequest;
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
 * Autorização: as campanhas são uma ferramenta de gestão (mensagens em
 * massa + lista de contactos dos clientes) — só o Administrador. Criar e
 * enviar validam-no nos FormRequests; as páginas de leitura aqui.
 */
class CampanhaController extends Controller
{
    public function index(): View
    {
        $this->autorizarGestao();

        return view('atelier::campanhas.index', [
            'campanhas' => Campanha::latest('id')->paginate(20),
        ]);
    }

    public function criar(): View
    {
        $this->autorizarGestao();

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

    public function mostrar(int $campanha): View
    {
        $this->autorizarGestao();

        $campanha = Campanha::findOrFail($campanha);

        return view('atelier::campanhas.mostrar', [
            'campanha' => $campanha->load('envios.cliente'),
        ]);
    }

    public function enviarAgora(EnviarCampanhaRequest $request, int $campanha): RedirectResponse
    {
        $campanha = Campanha::findOrFail($campanha);

        if ($campanha->estado === 'cancelada') {
            return back()->with('erro', 'Esta campanha foi cancelada.');
        }

        if ($campanha->estado === 'enviada') {
            // Já foi processada: só volta a correr se houver envios que
            // falharam (os já enviados são saltados pelo job — ninguém
            // recebe a mesma mensagem duas vezes).
            if (! $campanha->envios()->where('estado', 'falhou')->exists()) {
                return back()->with('erro', 'Esta campanha já foi enviada.');
            }

            Campanha::query()
                ->whereKey($campanha->id)
                ->where('estado', 'enviada')
                ->update(['estado' => 'agendada', 'iniciada_em' => null]);
        } elseif ($this->envioEmCurso($campanha)) {
            return back()->with('erro', 'O envio desta campanha já está em curso.');
        }

        DispararCampanhaJob::dispatch($campanha->id);

        return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
    }

    protected function autorizarGestao(): void
    {
        abort_unless(request()->user()?->hasRole('Administrador'), 403);
    }

    /** Há uma reivindicação recente (job a correr ou na fila). Uma antiga é tratada como abandonada. */
    protected function envioEmCurso(Campanha $campanha): bool
    {
        return $campanha->iniciada_em !== null
            && Carbon::parse($campanha->iniciada_em)->gt(now()->subHours(DispararCampanhaJob::VALIDADE_REIVINDICACAO_HORAS));
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Services/Notificacoes/WhatsAppCanal.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\TelefoneAngola;

/**
 * Dois fornecedores, escolhidos por ATELIER_WHATSAPP_DRIVER:
 *
 *  - 'zapi'  — Z-API: POST {base}/instances/{instância}/token/{token}/send-text,
 *              cabeçalho Client-Token, corpo {phone, message} (só dígitos).
 *  - 'cloud' — WhatsApp Business Cloud API da Meta (o canal original).
 *
 * Sem driver definido usa 'zapi' se ATELIER_ZAPI_INSTANCE_ID estiver
 * preenchido, senão 'cloud'. O telefone é normalizado para o formato
 * internacional (244…) antes do envio — ver TelefoneAngola.
 *
 * Nenhum dos dois foi testado aqui contra uma conta real: confirma o envio
 * com um número teu antes de produção. Com a Cloud API da Meta, fora da
 * janela de 24h de conversa é preciso um "template" pré-aprovado (texto
 * livre é recusado) — a Z-API não tem essa restrição.
 */
class WhatsAppCanal implements CanalNotificacaoInterface
{
    private const TIMEOUT_SEGUNDOS = 15;

    public function identificador(): string
    {
        return 'whatsapp';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $numero = TelefoneAngola::normalizar($destino);

        if ($numero === null) {
            throw NotificacaoException::numeroInvalido('whatsapp');
        }

        match ($this->driver()) {
            'zapi' => $this->enviarViaZApi($numero, $mensagem),
            default => $this->enviarViaCloudApi($numero, $mensagem),
        };
    }

    protected function driver(): string
    {
        $driver = strtolower(trim((string) config('atelier.whatsapp_driver')));

        if ($driver !== '') {
            return $driver;
        }

        return config('atelier.whatsapp_zapi.instance_id') ? 'zapi' : 'cloud';
    }

    protected function enviarViaZApi(string $numero, string $mensagem): void
    {
        $baseUrl = rtrim((string) config('atelier.whatsapp_zapi.base_url', 'https://api.z-api.io'), '/');
        $instancia = config('atelier.whatsapp_zapi.instance_id');
        $token = config('atelier.whatsapp_zapi.token');
        $clientToken = config('atelier.whatsapp_zapi.client_token');

        if (! $instancia || ! $token) {
            throw NotificacaoException::naoConfigurado('whatsapp');
        }

        try {
            $pedido = Http::timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->retry(2, 500, fn ($excecao) => $excecao instanceof ConnectionException, throw: false);

            if ($clientToken) {
                $pedido = $pedido->withHeaders(['Client-Token' => $clientToken]);
            }

            $resposta = $pedido->post("{$baseUrl}/instances/{$instancia}/token/{$token}/send-text", [
                'phone' => $numero,
                'message' => $mensagem,
            ]);
        } catch (ConnectionException) {
            throw NotificacaoException::falhaNoEnvio('whatsapp', 'não foi possível contactar o fornecedor (Z-API).');
        }

        if ($resposta->failed()) {
            Log::error('Falha ao enviar WhatsApp (Z-API)', [
                'destino' => TelefoneAngola::mascarar($numero),
                'estado_http' => $resposta->status(),
                'resposta' => $resposta->body(),
            ]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', 'o fornecedor recusou o envio (HTTP '.$resposta->status().').');
        }
    }

    protected function enviarViaCloudApi(string $numero, string $mensagem): void
    {
        $baseUrl = config('atelier.whatsapp.base_url');
        $token = config('atelier.whatsapp.token');
        $numeroId = config('atelier.whatsapp.numero_remetente_id');

        if (! $baseUrl || ! $token || ! $numeroId) {
            throw NotificacaoException::naoConfigurado('whatsapp');
        }

        try {
            $resposta = Http::withToken($token)
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->post("{$baseUrl}/{$numeroId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $numero,
                    'type' => 'text',
                    'text' => ['body' => $mensagem],
                ]);
        } catch (ConnectionException) {
            throw NotificacaoException::falhaNoEnvio('whatsapp', 'não foi possível contactar o fornecedor (Cloud API).');
        }

        if ($resposta->failed()) {
            Log::error('Falha ao enviar WhatsApp (Cloud API)', [
                'destino' => TelefoneAngola::mascarar($numero),
                'estado_http' => $resposta->status(),
                'resposta' => $resposta->body(),
            ]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', $resposta->body());
        }
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Services/Notificacoes/SmsCanal.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\TelefoneAngola;

/**
 * Genérico de propósito: o requisito não especifica qual gateway de SMS
 * angolano usar, ao contrário do pagamento (onde o README do Subscricoes
 * já tinha escolhido ProxyPay/Multicaixa). Assume um endpoint REST simples
 * (destino + mensagem + api_key) — troca o corpo do pedido pelo formato
 * exato do fornecedor escolhido antes de usar. O destino segue em formato
 * internacional só com dígitos (244923456789) — se o fornecedor exigir "+",
 * acrescenta-o aqui. Ver aviso equivalente em WhatsAppCanal.
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

        $numero = TelefoneAngola::normalizar($destino);

        if ($numero === null) {
            throw NotificacaoException::numeroInvalido('sms');
        }

        try {
            $resposta = Http::withToken($apiKey)
                ->timeout(15)
                ->post($baseUrl, [
                    'remetente' => config('atelier.sms.remetente'),
                    'destino' => $numero,
                    'mensagem' => $mensagem,
                ]);
        } catch (ConnectionException) {
            throw NotificacaoException::falhaNoEnvio('sms', 'não foi possível contactar o fornecedor.');
        }

        if ($resposta->failed()) {
            Log::error('Falha ao enviar SMS', [
                'destino' => TelefoneAngola::mascarar($numero),
                'estado_http' => $resposta->status(),
                'resposta' => $resposta->body(),
            ]);

            throw NotificacaoException::falhaNoEnvio('sms', $resposta->body());
        }
    }
}
__ATELIER_FIM__

# ---- jobs --------------------------------------------------------------------
escrever Modules/Atelier/app/Jobs/LembretesProvaJob.php <<'__ATELIER_FIM__'
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
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()).
 * Percorre provas de TODAS as empresas — por isso corre dentro do bypass
 * explícito do TenantManager, tal como VerificarSubscricoesExpiradasJob já
 * faz no módulo Subscricoes.
 *
 * - Só trata empresas com o serviço Atelier e acesso ativo (nada de gastar
 *   mensagens com empresas que cancelaram).
 * - O lembrete é "reivindicado" ANTES do envio (UPDATE atómica de
 *   lembrete_enviado_em): duas execuções sobrepostas nunca enviam o mesmo.
 * - Só fica marcado como enviado se algum canal o aceitou; senão a
 *   reivindicação é libertada e a próxima hora tenta de novo.
 */
class LembretesProvaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(TenantManager $tenantManager, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($notificador) {
            /** @var array<int, bool> $elegiveis */
            $elegiveis = [];

            Prova::query()
                ->whereIn('estado', ['agendada', 'confirmada', 'reagendada'])
                ->whereNull('lembrete_enviado_em')
                ->where('data_hora_agendada', '>=', now())
                ->with(['pedido.cliente', 'pedido.empresa'])
                ->chunkById(100, function ($provas) use ($notificador, &$elegiveis) {
                    foreach ($provas as $prova) {
                        if (! $prova->precisaDeLembrete()) {
                            continue;
                        }

                        $empresa = $prova->pedido?->empresa;

                        if (! $empresa) {
                            continue;
                        }

                        $elegiveis[$empresa->id] ??= ElegibilidadeAutomacao::empresaPodeEnviar($empresa);

                        if ($elegiveis[$empresa->id]) {
                            $this->processar($prova, $notificador);
                        }
                    }
                });
        });
    }

    protected function processar(Prova $prova, NotificadorClienteService $notificador): void
    {
        $reivindicado = Prova::query()
            ->whereKey($prova->id)
            ->whereNull('lembrete_enviado_em')
            ->update(['lembrete_enviado_em' => now()]);

        if ($reivindicado === 0) {
            return;
        }

        try {
            $enviado = $this->enviarLembrete($prova, $notificador);
        } catch (Throwable $e) {
            $enviado = false;

            Log::error('Atelier: falha ao enviar lembrete de prova', [
                'prova_id' => $prova->id,
                'erro' => $e->getMessage(),
            ]);
        }

        if (! $enviado) {
            // Nada foi entregue: liberta a reivindicação para a próxima execução tentar de novo.
            Prova::query()->whereKey($prova->id)->update(['lembrete_enviado_em' => null]);
        }
    }

    protected function enviarLembrete(Prova $prova, NotificadorClienteService $notificador): bool
    {
        $cliente = $prova->pedido?->cliente;

        if (! $cliente) {
            return false;
        }

        return $notificador->notificar(
            $cliente,
            "Lembrete: {$prova->tipoRotulo()} — {$prova->pedido->empresa->nome_comercial}",
            "Lembramos que tem a {$prova->tipoRotulo()} da sua peça agendada para ".
                $prova->data_hora_agendada->format('d/m/Y \à\s H:i').'.',
        );
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Jobs/VerificarAniversariosJob.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Throwable;

/**
 * Único gatilho de CRM verdadeiramente AUTOMÁTICO (requisito D.1: "Mensagens
 * automáticas em datas comemorativas") — corre uma vez por dia. As
 * promoções sazonais e a reativação de inativos são, de propósito,
 * campanhas manuais (ver Admin\CampanhaController) e não entram aqui.
 *
 * Só empresas com o Atelier ativo. Cada aniversário é "reivindicado" com
 * uma UPDATE atómica de aniversario_enviado_ano antes do envio: um retry da
 * fila ou uma segunda execução no mesmo dia nunca felicita duas vezes. Se
 * nenhum canal aceitar a mensagem, a reivindicação é libertada.
 */
class VerificarAniversariosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(TenantManager $tenantManager, CrmService $crmService, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($crmService, $notificador) {
            Empresa::query()->with('servicos')->chunkById(100, function ($empresas) use ($crmService, $notificador) {
                foreach ($empresas as $empresa) {
                    if (! ElegibilidadeAutomacao::empresaPodeEnviar($empresa)) {
                        continue;
                    }

                    try {
                        foreach ($crmService->aniversariantesHoje($empresa->id) as $cliente) {
                            $this->felicitar($empresa, $cliente, $notificador);
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

    protected function felicitar(Empresa $empresa, Cliente $cliente, NotificadorClienteService $notificador): void
    {
        $ano = (int) now()->year;

        $reivindicado = ClienteCrm::query()
            ->where('cliente_id', $cliente->id)
            ->where(function ($q) use ($ano) {
                $q->whereNull('aniversario_enviado_ano')->orWhere('aniversario_enviado_ano', '<', $ano);
            })
            ->update(['aniversario_enviado_ano' => $ano]);

        if ($reivindicado === 0) {
            return;
        }

        try {
            $enviado = $notificador->notificar(
                $cliente,
                "Feliz Aniversário! — {$empresa->nome_comercial}",
                "Hoje é um dia especial! A equipa do {$empresa->nome_comercial} deseja-lhe um feliz aniversário.",
            );
        } catch (Throwable $e) {
            $enviado = false;

            Log::error('Atelier: falha ao enviar mensagem de aniversário', [
                'cliente_id' => $cliente->id,
                'erro' => $e->getMessage(),
            ]);
        }

        if (! $enviado) {
            ClienteCrm::query()->where('cliente_id', $cliente->id)->update(['aniversario_enviado_ano' => null]);
        }
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Jobs/EnviarCampanhasAgendadasJob.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Services\TenantManager;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()) e
 * despacha DispararCampanhaJob para cada campanha agendada cuja hora já
 * chegou. Ignora as que já foram reivindicadas por um job (iniciada_em
 * recente) — antes, uma fila atrasada mais de uma hora fazia com que a
 * mesma campanha fosse despachada outra vez — e as de empresas sem o
 * Atelier ativo. Percorre campanhas de TODAS as empresas, por isso corre
 * dentro do bypass explícito do TenantManager.
 */
class EnviarCampanhasAgendadasJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager): void
    {
        $tenantManager->semTenant(function () {
            /** @var array<int, bool> $elegiveis */
            $elegiveis = [];

            Campanha::query()
                ->with('empresa')
                ->where('estado', 'agendada')
                ->where('agendada_para', '<=', now())
                ->where(function ($q) {
                    $q->whereNull('iniciada_em')
                        ->orWhere('iniciada_em', '<', now()->subHours(DispararCampanhaJob::VALIDADE_REIVINDICACAO_HORAS));
                })
                ->chunkById(100, function ($campanhas) use (&$elegiveis) {
                    foreach ($campanhas as $campanha) {
                        $empresa = $campanha->empresa;

                        if (! $empresa) {
                            continue;
                        }

                        $elegiveis[$empresa->id] ??= ElegibilidadeAutomacao::empresaPodeEnviar($empresa);

                        if ($elegiveis[$empresa->id]) {
                            DispararCampanhaJob::dispatch($campanha->id);
                        }
                    }
                });
        });
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/app/Jobs/DispararCampanhaJob.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
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
 * Anti-duplicados em DOIS níveis:
 *  1. A campanha é "reivindicada" com uma UPDATE atómica (iniciada_em passa
 *     de NULL para agora): só um job avança, por muitos que estejam na fila
 *     (duplo clique, job horário, retry). Uma reivindicação com mais de
 *     VALIDADE_REIVINDICACAO_HORAS é tratada como abandonada (worker morto).
 *  2. Por destinatário: a unique constraint em atelier_campanha_envios
 *     (campanha_id, cliente_id) + firstOrCreate() + o estado 'enviado'
 *     garantem que, num reenvio dos falhados, ninguém recebe duas vezes.
 */
class DispararCampanhaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const VALIDADE_REIVINDICACAO_HORAS = 2;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(protected int $campanhaId) {}

    public function handle(TenantManager $tenantManager, CrmService $crmService, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($crmService, $notificador) {
            $campanha = Campanha::with('empresa')->find($this->campanhaId);

            if (! $campanha || ! in_array($campanha->estado, ['rascunho', 'agendada'], true)) {
                return;
            }

            if (! $campanha->empresa || ! ElegibilidadeAutomacao::empresaPodeEnviar($campanha->empresa)) {
                Log::warning('Atelier: campanha não enviada — a empresa já não tem o serviço Atelier ativo.', [
                    'campanha_id' => $campanha->id,
                ]);

                return;
            }

            $reivindicada = Campanha::query()
                ->whereKey($campanha->id)
                ->whereIn('estado', ['rascunho', 'agendada'])
                ->where(function ($q) {
                    $q->whereNull('iniciada_em')
                        ->orWhere('iniciada_em', '<', now()->subHours(self::VALIDADE_REIVINDICACAO_HORAS));
                })
                ->update(['iniciada_em' => now()]);

            if ($reivindicada === 0) {
                return; // outro job já pegou nesta campanha
            }

            $assunto = $campanha->assunto ?: $campanha->nome;

            // WhatsApp/SMS em rajada levam ao bloqueio do número: pausa entre mensagens.
            $pausaMs = $campanha->canal === 'mail' ? 0 : (int) config('atelier.campanha_pausa_ms', 0);
            $enviados = 0;
            $falhados = 0;

            foreach ($crmService->resolverSegmento($campanha) as $cliente) {
                $envio = CampanhaEnvio::firstOrCreate(
                    ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
                    ['canal' => $campanha->canal, 'estado' => 'pendente'],
                );

                if ($envio->estado === 'enviado') {
                    continue;
                }

                try {
                    $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $campanha->mensagem);
                    $envio->update(['estado' => 'enviado', 'enviado_em' => now(), 'erro' => null]);
                    $enviados++;
                } catch (Throwable $e) {
                    $envio->update(['estado' => 'falhou', 'erro' => $e->getMessage()]);
                    $falhados++;

                    Log::warning('Atelier: falha ao enviar campanha para um cliente', [
                        'campanha_id' => $campanha->id,
                        'cliente_id' => $cliente->id,
                        'erro' => $e->getMessage(),
                    ]);
                }

                if ($pausaMs > 0) {
                    usleep($pausaMs * 1000);
                }
            }

            $campanha->update(['estado' => 'enviada', 'enviada_em' => now()]);

            Log::info('Atelier: campanha processada', [
                'campanha_id' => $campanha->id,
                'enviados' => $enviados,
                'falhados' => $falhados,
            ]);
        });
    }

    /** Falha geral (exceção ou timeout): liberta a reivindicação para permitir novo envio. */
    public function failed(Throwable $e): void
    {
        app(TenantManager::class)->semTenant(function () {
            Campanha::query()
                ->whereKey($this->campanhaId)
                ->where('estado', '!=', 'enviada')
                ->update(['iniciada_em' => null]);
        });

        Log::error('Atelier: o envio da campanha falhou', [
            'campanha_id' => $this->campanhaId,
            'erro' => $e->getMessage(),
        ]);
    }
}
__ATELIER_FIM__

# ---- testes novos -------------------------------------------------------------
escrever Modules/Atelier/tests/Feature/TelefoneAngolaTest.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Tests\Feature;

use Modules\Atelier\Support\TelefoneAngola;
use Tests\TestCase;

class TelefoneAngolaTest extends TestCase
{
    public function test_normaliza_os_formatos_angolanos_mais_comuns(): void
    {
        foreach (['923456789', '923 456 789', '+244 923 456 789', '00244923456789', '244923456789', '(+244) 923-456-789'] as $bruto) {
            $this->assertSame('244923456789', TelefoneAngola::normalizar($bruto, '244'), "Falhou com: {$bruto}");
        }
    }

    public function test_aceita_numeros_internacionais_de_outros_paises(): void
    {
        $this->assertSame('351912345678', TelefoneAngola::normalizar('+351 912 345 678', '244'));
    }

    public function test_rejeita_numeros_invalidos(): void
    {
        foreach ([null, '', 'abc', '12345', '222123456', '2449234567', '0123456789'] as $invalido) {
            $this->assertNull(TelefoneAngola::normalizar($invalido, '244'), 'Devia ser inválido: '.var_export($invalido, true));
        }
    }

    public function test_mascara_o_meio_do_numero_para_os_logs(): void
    {
        $this->assertSame('24492****89', TelefoneAngola::mascarar('244923456789'));
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/tests/Feature/PedidoTotalPorLinhaTest.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoMaterial;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * O total do pedido tem de coincidir, ao cêntimo, com o da fatura final:
 * a AGT exige o IVA arredondado por excesso LINHA A LINHA (Dinheiro::calcularLinha).
 */
class PedidoTotalPorLinhaTest extends TestCase
{
    use RefreshDatabase;

    protected function novoPedido(string $nif, float $valor, int $taxa = 14): Pedido
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Teste',
            'valor_orcamento' => $valor,
            'taxa_iva_aplicada' => $taxa,
        ])->fresh();
    }

    public function test_o_iva_e_arredondado_por_excesso_como_na_fatura(): void
    {
        $pedido = $this->novoPedido('400000301', 100.01);

        // 100,01 × 14% = 14,0014 → IVA por excesso 14,01 → total 114,02
        // (um round() simples do total daria 114,01 e discordava da fatura).
        $this->assertSame(11402, $pedido->totalComIvaCentimos());
        $this->assertEquals(114.02, $pedido->valorTotalComIva());
    }

    public function test_os_materiais_entram_linha_a_linha_no_total(): void
    {
        $pedido = $this->novoPedido('400000302', 8000);

        foreach ([['Tecido', 3, 150], ['Botões', 10, 25]] as $indice => [$descricao, $quantidade, $valorUnitario]) {
            $material = new PedidoMaterial([
                'pedido_id' => $pedido->id,
                'descricao' => $descricao,
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'ordem' => $indice,
            ]);
            $material->calcularValores()->save();
        }

        // (8000 + 450 + 250) × 1,14 = 9918 — todas as linhas têm IVA exato.
        $this->assertSame(991800, $pedido->fresh()->totalComIvaCentimos());
    }

    public function test_o_saldo_em_falta_nunca_e_negativo(): void
    {
        $pedido = $this->novoPedido('400000303', 100); // total 114,00

        $pedido->valor_sinal = 50;
        $this->assertEquals(64.0, $pedido->saldoEmFalta());

        $pedido->valor_sinal = 500; // mais do que o total: nunca pode dar negativo
        $this->assertEquals(0.0, $pedido->saldoEmFalta());
    }
}
__ATELIER_FIM__

escrever Modules/Atelier/tests/Feature/CrmOptInTest.php <<'__ATELIER_FIM__'
<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CrmService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

class CrmOptInTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Campanha, 1: Cliente, 2: Cliente, 3: Cliente} */
    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);

        $aceitou = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Aceitou']);
        $semRegisto = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem registo']);
        $recusou = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Recusou']);

        ClienteCrm::create(['cliente_id' => $aceitou->id, 'aceita_marketing' => true]);
        ClienteCrm::create(['cliente_id' => $recusou->id, 'aceita_marketing' => false]);

        $campanha = (new Campanha)->forceFill(['empresa_id' => $empresa->id, 'segmento' => 'todos']);

        return [$campanha, $aceitou, $semRegisto, $recusou];
    }

    public function test_por_omissao_so_recebe_marketing_quem_deu_consentimento_explicito(): void
    {
        [$campanha, $aceitou] = $this->cenario('400000401');

        $ids = app(CrmService::class)->resolverSegmento($campanha)->pluck('id')->all();

        $this->assertSame([$aceitou->id], $ids);
    }

    public function test_com_a_exigencia_desligada_so_ficam_de_fora_os_que_recusaram(): void
    {
        config(['atelier.marketing_exigir_opt_in' => false]);

        [$campanha, $aceitou, $semRegisto] = $this->cenario('400000402');

        $ids = app(CrmService::class)->resolverSegmento($campanha)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$aceitou->id, $semRegisto->id], $ids);
    }
}
__ATELIER_FIM__

# ---- alterações cirúrgicas (substituições literais, com verificação de contagem) ----
info "A aplicar alterações cirúrgicas…"

# Rotas: whereNumber em todos os parâmetros numéricos (antes, /pedidos/abc dava 500 em vez de 404),
# throttle no portal e as 2 rotas novas do CRM.
ROTAS=Modules/Atelier/routes/web.php
if [ "$SIMULAR" -eq 1 ]; then ok "(simulação) $ROTAS"
elif [ ! -f "$ROTAS" ]; then aviso "Em falta: $ROTAS"; FALHAS=$((FALHAS+1))
elif grep -q 'whereNumber' "$ROTAS"; then aviso "$ROTAS já tem whereNumber — não repito essa parte."
else
  perl -i.bak -pe '
    if (/->name\(/) {
      my @p;
      for my $k (qw(pedido prova cliente portfolioItem campanha pedidoOrcamento)) {
        push @p, "\x27$k\x27" if /\{$k\}/;
      }
      if (@p) {
        my $arg = @p > 1 ? "[" . join(", ", @p) . "]" : $p[0];
        s/->name\(/->whereNumber($arg)->name(/;
      }
    }
  ' "$ROTAS" && rm -f "$ROTAS.bak" && ESCRITOS+=("$ROTAS") && ok "$ROTAS (whereNumber)"
  subst "$ROTAS" 1 "->name('provas.reagendar');" "->whereNumber(['cliente', 'prova'])
            ->name('provas.reagendar');"
  subst "$ROTAS" 1 "Route::middleware(['signed'])" "Route::middleware(['throttle:60,1', 'signed'])"
  subst "$ROTAS" 1 "use Modules\\Atelier\\Http\\Controllers\\Admin\\CampanhaController;" "use Modules\\Atelier\\Http\\Controllers\\Admin\\CampanhaController;
use Modules\\Atelier\\Http\\Controllers\\Admin\\ClienteCrmController;"
  subst "$ROTAS" 1 "Route::post('medidas', [MedidaController::class, 'guardar'])->name('medidas.guardar');" "Route::post('medidas', [MedidaController::class, 'guardar'])->name('medidas.guardar');

        Route::get('clientes/{cliente}/crm', [ClienteCrmController::class, 'editar'])->whereNumber('cliente')->name('crm.editar');
        Route::put('clientes/{cliente}/crm', [ClienteCrmController::class, 'atualizar'])->whereNumber('cliente')->name('crm.atualizar');"
fi

# PedidoController: erros de negócio/validação do Faturação passam a aparecer com a causa real
# (antes: "Tenta novamente" ou um 500 em gerarFaturaFinal); o sinal já não passa por float.
PC=Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php
subst "$PC" 4 '} catch (PedidoException $e) {' '} catch (PedidoException|\DomainException|\InvalidArgumentException $e) {'
subst "$PC" 1 "(float) \$request->validated('valor')" "\$request->validated('valor')"
subst "$PC" 1 '$pedido->update($request->validated());' "abort_if(\$pedido->estaFinalizado(), 422, 'Este pedido já está finalizado e não pode ser editado.');
        \$pedido->update(\$request->validated());"

subst "$PC" 1 "'proximosEstados' => \$this->statusService->proximosEstadosPossiveis(\$pedido)," "'proximosEstados' => \$this->estadosVisiveisParaUtilizador(\$pedido),"
subst "$PC" 1 'public function atualizar(AtualizarPedidoRequest $request, int $pedido): RedirectResponse' "/**
     * A Costureira só vê (e só pode usar) os botões dos pedidos que lhe foram
     * atribuídos, e nunca os de entrega/cancelamento — em linha com
     * AvancarEstadoPedidoRequest, que é quem realmente decide.
     *
     * @return array<int, string>
     */
    protected function estadosVisiveisParaUtilizador(Pedido \$pedido): array
    {
        \$estados = \$this->statusService->proximosEstadosPossiveis(\$pedido);
        \$utilizador = request()->user();

        if (! \$utilizador->hasRole('Costureira') || \$utilizador->hasAnyRole(['Administrador', 'Secretária'])) {
            return \$estados;
        }

        if ((int) \$pedido->responsavel_id !== (int) \$utilizador->id) {
            return [];
        }

        return array_values(array_diff(\$estados, ['entregue', 'cancelado']));
    }

    public function atualizar(AtualizarPedidoRequest \$request, int \$pedido): RedirectResponse"

# ProvaController: não agendar provas em pedidos finalizados; limpar a proposta do cliente quando já foi tratada.
PV=Modules/Atelier/app/Http/Controllers/Admin/ProvaController.php
subst "$PV" 1 '$pedido = Pedido::findOrFail($pedido);' "\$pedido = Pedido::findOrFail(\$pedido);

        abort_if(\$pedido->estaFinalizado(), 422, 'Não é possível agendar provas num pedido já finalizado.');"
subst "$PV" 1 "\$dados = ['estado' => \$request->validated('estado')];" "\$dados = ['estado' => \$request->validated('estado')];

        if (\$dados['estado'] !== 'reagendada') {
            // A proposta do cliente já foi tratada (aceite com nova data, ou recusada).
            \$dados['data_hora_proposta_cliente'] = null;
        }"

# Portal: não reagendar provas concluídas nem de pedidos finalizados; data proposta até 90 dias.
PO=Modules/Atelier/app/Http/Controllers/Publico/PortalController.php
subst "$PO" 1 '$provaModel = $this->resolverProva(provaId: $prova, clienteId: $clienteModel->id);' "\$provaModel = \$this->resolverProva(provaId: \$prova, clienteId: \$clienteModel->id);

        abort_if(\$provaModel->estado === 'concluida' || \$provaModel->pedido?->estaFinalizado(), 404);"
subst "$PO" 1 "'nova_data_hora' => ['required', 'date', 'after:now']," "'nova_data_hora' => ['required', 'date', 'after:now', 'before:+90 days'],"

# Landing: honeypot contra spam no formulário de orçamento (o campo escondido é preenchido por bots).
LC=Modules/Atelier/app/Http/Controllers/Publico/LandingController.php
subst "$LC" 1 'PedidoOrcamento::create([' "// Honeypot: humanos nunca preenchem o campo escondido; um bot sim. Finge sucesso e não guarda nada.
        if (\$request->filled('confirmacao_extra')) {
            return back()->with('sucesso', 'Pedido enviado! Entraremos em contacto brevemente.');
        }

        PedidoOrcamento::create(["
subst Modules/Atelier/resources/views/landing/mostrar.blade.php 1 '@csrf' '@csrf
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <input type="text" name="confirmacao_extra" tabindex="-1" autocomplete="off">
            </div>'

# Portfólio: comparação de posse sem depender do tipo (int vs string) devolvido pelo driver.
subst Modules/Atelier/app/Http/Controllers/Admin/PortfolioController.php 1 'abort_unless($portfolioItem->empresa_id === request()->user()->empresa_id, 403);' 'abort_unless((int) $portfolioItem->empresa_id === (int) request()->user()->empresa_id, 403);'

# Orçamentos (leads com contactos): só Administrador/Secretária.
subst Modules/Atelier/app/Http/Controllers/Admin/OrcamentoController.php 1 "return view('atelier::orcamentos.index', [" "abort_unless(request()->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);

        return view('atelier::orcamentos.index', ["

# Ligação à ficha de CRM a partir do pedido (só para quem pode editá-la).
subst Modules/Atelier/resources/views/pedidos/mostrar.blade.php 1 '<p class="text-sm text-slate-500">{{ $pedido->tipoServicoRotulo() }}</p>' '<p class="text-sm text-slate-500">{{ $pedido->tipoServicoRotulo() }}</p>
            @if ($podeVerFinancas && $pedido->cliente_id)
                <p class="mt-1 text-xs"><a href="{{ route('"'"'atelier.crm.editar'"'"', $pedido->cliente_id) }}" class="text-indigo-600 hover:underline">Dados do cliente (aniversário e consentimento)</a></p>
            @endif'

# Mensagem coerente quando não há botões (estado final OU sem permissão).
subst Modules/Atelier/resources/views/pedidos/mostrar.blade.php 1 '<p class="text-sm text-slate-500">Este pedido está num estado final.</p>' "<p class=\"text-sm text-slate-500\">{{ \$pedido->estaFinalizado() ? 'Este pedido está num estado final.' : 'Não tens ações disponíveis neste pedido.' }}</p>"

# Campanhas: botão "Reenviar falhados" quando a campanha terminou com falhas.
CV=Modules/Atelier/resources/views/campanhas/mostrar.blade.php
subst "$CV" 1 "@if (\$campanha->estado !== 'enviada')" "@php \$temFalhas = \$campanha->estado === 'enviada' && \$campanha->envios->contains('estado', 'falhou'); @endphp
        @if (\$campanha->estado !== 'enviada' || \$temFalhas)"
if [ "$SIMULAR" -eq 0 ] && [ -f "$CV" ]; then
  perl -0777 -i.bak -pe '
    $n += s/^([ \t]*)Enviar agora([ \t\r]*)$/$1\{\{ \$campanha->estado === \x27enviada\x27 ? \x27Reenviar falhados\x27 : \x27Enviar agora\x27 \}\}$2/m;
    END { exit($n == 1 ? 0 : 3) }
  ' "$CV"
  if [ $? -eq 0 ]; then rm -f "$CV.bak"; ok "$CV (rótulo do botão)"
  else mv -f "$CV.bak" "$CV"; aviso "$CV: não encontrei a linha do botão «Enviar agora» — o botão fica com o rótulo antigo."; FALHAS=$((FALHAS+1)); fi
fi

# Configuração nova (acrescentada ao fim de config/config.php) e variáveis de ambiente.
CFG=Modules/Atelier/config/config.php
if [ "$SIMULAR" -eq 1 ]; then ok "(simulação) $CFG"
elif [ ! -f "$CFG" ]; then aviso "Em falta: $CFG"; FALHAS=$((FALHAS+1))
elif grep -q 'marketing_exigir_opt_in' "$CFG"; then aviso "$CFG já tem a configuração nova — não repito."
elif [ "$(grep -v '^[[:space:]]*$' "$CFG" | tail -n 1 | tr -d '\r')" != '];' ]; then
  aviso "$CFG não termina em «];» — acrescenta à mão as chaves novas (ver README da auditoria)."; FALHAS=$((FALHAS+1))
else
  NOVO_CFG=$(cat <<'__ATELIER_FIM__'
    /*
    |--------------------------------------------------------------------------
    | Auditoria (out/2026)
    |--------------------------------------------------------------------------
    */

    // Marketing só para clientes com consentimento explícito (ClienteCrm::aceita_marketing = true).
    // false = recebem todos menos os que recusaram (comportamento antigo).
    'marketing_exigir_opt_in' => (bool) env('ATELIER_MARKETING_OPT_IN_EXPLICITO', true),

    // Pausa entre mensagens de uma campanha por WhatsApp/SMS (ms) — rajadas levam ao bloqueio do número.
    'campanha_pausa_ms' => (int) env('ATELIER_CAMPANHA_PAUSA_MS', 500),

    // true = um pedido só passa a "entregue" depois de o saldo estar pago.
    'exigir_pagamento_para_entrega' => (bool) env('ATELIER_EXIGIR_PAGAMENTO_PARA_ENTREGA', false),

    // Motivo de isenção (códigos de faturacao.motivos_isencao) usado quando o pedido tem IVA a 0%.
    'motivo_isencao' => env('ATELIER_MOTIVO_ISENCAO'),

    // Código do país acrescentado aos números nacionais de 9 dígitos (Angola = 244).
    'telefone_codigo_pais' => env('ATELIER_TELEFONE_CODIGO_PAIS', '244'),

    // WhatsApp: 'zapi' (Z-API) ou 'cloud' (Meta). Vazio = zapi se ATELIER_ZAPI_INSTANCE_ID existir, senão cloud.
    'whatsapp_driver' => env('ATELIER_WHATSAPP_DRIVER'),
    'whatsapp_zapi' => [
        'base_url' => env('ATELIER_ZAPI_BASE_URL', 'https://api.z-api.io'),
        'instance_id' => env('ATELIER_ZAPI_INSTANCE_ID'),
        'token' => env('ATELIER_ZAPI_TOKEN'),
        'client_token' => env('ATELIER_ZAPI_CLIENT_TOKEN'),
    ],
__ATELIER_FIM__
)
  NOVO="$NOVO_CFG" perl -0777 -i.bak -pe 'BEGIN{$n=$ENV{NOVO}} s/\n\];\s*\z/\n\n$n\n];\n/' "$CFG" && rm -f "$CFG.bak" && ESCRITOS+=("$CFG") && ok "$CFG"
fi

if [ "$SIMULAR" -eq 0 ] && [ -f .env.example ] && ! grep -q 'ATELIER_ZAPI_INSTANCE_ID' .env.example; then
  cat >> .env.example <<'__ATELIER_FIM__'

# --- Atelier (auditoria out/2026) ---
ATELIER_WHATSAPP_DRIVER=zapi
ATELIER_ZAPI_INSTANCE_ID=
ATELIER_ZAPI_TOKEN=
ATELIER_ZAPI_CLIENT_TOKEN=
ATELIER_MARKETING_OPT_IN_EXPLICITO=true
ATELIER_EXIGIR_PAGAMENTO_PARA_ENTREGA=false
ATELIER_MOTIVO_ISENCAO=
__ATELIER_FIM__
  ESCRITOS+=(.env.example); ok ".env.example"
fi

# ---------------------------------------------------------------- 3. lint ----
if [ "$SIMULAR" -eq 1 ]; then
  info "Simulação concluída — nada foi escrito. Corre sem --simular para aplicar."
  exit 0
fi

mapfile -t UNICOS < <(printf '%s\n' ${ESCRITOS[@]+"${ESCRITOS[@]}"} | grep -v '^$' | sort -u)

ERROS_LINT=()
if [ "$SEM_LINT" -eq 0 ]; then
  info "A verificar sintaxe PHP (php -l)…"
  PHP_BIN="${PHP_BIN:-}"
  if [ -z "$PHP_BIN" ]; then
    for c in php php.exe /c/xampp/php/php.exe /mnt/c/xampp/php/php.exe; do
      command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$c"; break; }
    done
  fi
  if [ -z "$PHP_BIN" ]; then
    aviso "php não encontrado no PATH — lint saltado. Corre depois:  for f in \$(git diff --name-only $RAMO_ANTERIOR -- '*.php'); do php -l \"\$f\"; done"
  else
    for f in "${UNICOS[@]}"; do
      case "$f" in *.php) "$PHP_BIN" -l "$f" >/dev/null 2>&1 || ERROS_LINT+=("$f") ;; esac
    done
    if [ "${#ERROS_LINT[@]}" -eq 0 ]; then ok "todos os ficheiros PHP passam o php -l"; fi
  fi
fi

if [ "${#ERROS_LINT[@]}" -gt 0 ]; then
  erro "Erro de sintaxe PHP em:"; printf '    %s\n' "${ERROS_LINT[@]}"
  echo "Nada foi commitado. Vê o erro com  php -l <ficheiro>  ou volta atrás:  git checkout -- . && git checkout $RAMO_ANTERIOR && git branch -D $RAMO"
  exit 1
fi

# -------------------------------------------------------------- 4. commit ----
git add -- "${UNICOS[@]}" 2>/dev/null
if git commit -q -m "fix(atelier): correções da auditoria ao módulo (campanhas, faturação, jobs, CRM, WhatsApp Z-API)" 2>/dev/null; then
  ok "commit criado no ramo $RAMO"
else
  aviso "Não consegui fazer commit (user.name/user.email configurados?). As alterações ficaram no ramo $RAMO, por commitar."
fi

# ------------------------------------------------------------- 5. resumo ----
info "Concluído: ${#UNICOS[@]} ficheiro(s) tocados."
if [ "$FALHAS" -gt 0 ] || [ "${#AVISOS[@]}" -gt 0 ]; then
  printf '\n\033[33mAvisos (%s):\033[0m\n' "${#AVISOS[@]}"; printf '  - %s\n' "${AVISOS[@]}"
fi
cat <<EOF

Próximos passos:
  1. php artisan migrate        (2 migrations: campanhas.iniciada_em e clientes_crm)
  2. Confirma que o phpunit.xml usa uma BD de TESTE e corre:  php artisan test Modules/Atelier
  3. php artisan route:list --path=atelier ; php artisan view:clear
  4. .env — Z-API: ATELIER_WHATSAPP_DRIVER=zapi + ATELIER_ZAPI_INSTANCE_ID / _TOKEN / _CLIENT_TOKEN
  5. Marketing: por omissão só recebe quem tiver consentimento. Regista-o em
     Pedido → «Dados do cliente (aniversário e consentimento)». Para o comportamento
     antigo: ATELIER_MARKETING_OPT_IN_EXPLICITO=false
  6. Com a AGT ativa o sinal (recibo de adiantamento) não é suportado pelo Faturação:
     a app agora diz isso em vez de falhar em silêncio.

Rever:    git diff $RAMO_ANTERIOR..$RAMO --stat
Desfazer: git checkout $RAMO_ANTERIOR && git branch -D $RAMO
EOF
