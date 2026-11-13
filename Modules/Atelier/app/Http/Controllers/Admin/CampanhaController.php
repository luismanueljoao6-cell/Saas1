<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarCampanhaRequest;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\CampanhaReenvio;
use Throwable;

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

        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $campanhas = Campanha::query()
            ->when($dados['q'] ?? null, fn ($query, string $termo) => $query->where(function ($filtro) use ($termo): void {
                $filtro->where('nome', 'like', '%'.trim($termo).'%')
                    ->orWhere('canal', 'like', '%'.trim($termo).'%')
                    ->orWhere('estado', 'like', '%'.trim($termo).'%');
            }))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('atelier::campanhas.index', compact('campanhas'));
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

        // O pedido é validado E registado na mesma transação, com lock de linha
        // na campanha: dois pedidos simultâneos são serializados aqui e só o
        // primeiro fica com o reenvio. O job nunca decide sozinho se há reenvio
        // — só corre se levar o token deste pedido.
        //
        // Os pedidos recusados voltam à página com uma mensagem (nunca uma
        // página de erro): num clique duplo o navegador mostra a resposta do
        // SEGUNDO pedido, e o utilizador não pode ver um erro quando o primeiro
        // foi aceite.
        [$campanha, $pedido, $recusa] = DB::transaction(function () use ($request, $campanha): array {
            $campanha = Campanha::query()->lockForUpdate()->findOrFail($campanha);

            if ($campanha->estado === 'cancelada') {
                return [$campanha, null, ['erro', 'Esta campanha foi cancelada.']];
            }

            $reenvio = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
            $temFalhas = $campanha->envios()->where('estado', CampanhaEnvio::ESTADO_FALHOU)->exists();

            if (! ($reenvio || in_array($campanha->estado, ['rascunho', 'agendada'], true))
                || ($reenvio && ! $temFalhas)) {
                return [$campanha, null, ['erro', 'Esta campanha não pode ser enviada ou não tem envios falhados para repetir.']];
            }

            // Primeiro envio: o lock por campanha e a idempotência dos envios
            // já tornam um clique duplo inofensivo.
            if (! $reenvio) {
                return [$campanha, null, null];
            }

            CampanhaReenvio::expirarAbandonados($campanha->id);

            $emCurso = ['sucesso', 'Já existe um reenvio em curso para esta campanha — os estados vão aparecer aqui à medida que forem processados.'];

            if (CampanhaReenvio::query()->where('campanha_id', $campanha->id)->where('aberto', true)->exists()) {
                return [$campanha, null, $emCurso];
            }

            try {
                // Savepoint próprio: se a unique (campanha_id, aberto) disparar,
                // só esta inserção é desfeita e a transação externa continua válida.
                $pedido = DB::transaction(fn () => CampanhaReenvio::create([
                    'empresa_id' => $campanha->empresa_id,
                    'campanha_id' => $campanha->id,
                    'token' => (string) Str::uuid(),
                    'estado' => CampanhaReenvio::ACEITE,
                    'aberto' => true,
                    'solicitado_por_id' => $request->user()->id,
                    'solicitado_em' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Rede de segurança caso o lock de linha não esteja disponível no driver.
                return [$campanha, null, $emCurso];
            }

            return [$campanha, $pedido, null];
        });

        if ($recusa !== null) {
            return back()->with($recusa[0], $recusa[1]);
        }

        try {
            DispararCampanhaJob::dispatch($campanha->id, $pedido?->token);
        } catch (Throwable $e) {
            // Sem job na fila o pedido ficaria aberto e bloquearia novos reenvios.
            $pedido?->fechar(CampanhaReenvio::FALHADO, 'Não foi possível colocar o job na fila: '.$e->getMessage());

            throw $e;
        }

        return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
    }

    protected function autorizar(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Administrador'), 403);
    }
}
