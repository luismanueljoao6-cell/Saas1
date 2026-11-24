<?php

declare(strict_types=1);

/*
 * Worker dos testes de concorrência real. Uso (lançado pelo harness):
 *   php tests/Support/concorrencia_worker.php <acao> <barreira_microtime> <json_args>
 * Arranca a aplicação, espera pela barreira e executa UMA ação, imprimindo uma linha JSON.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Atelier\Http\Controllers\Admin\CampanhaController;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Services\FaturacaoEstudioService;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboAlocacaoService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Services\PagamentoService;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';

$acao = (string) ($argv[1] ?? '');
$barreira = (float) ($argv[2] ?? 0);
$args = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];

// Carga mista: um mesmo lote de processos executa acções diferentes (['acao' => ..., 'args' => [...]]).
if ($acao === 'misto') {
    $acao = (string) ($args['acao'] ?? '');
    $args = (array) ($args['args'] ?? []);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Liga já a base de dados: o custo da ligação não pode entrar depois da barreira.
DB::connection()->getPdo();

while (microtime(true) < $barreira) {
    usleep(200);
}

try {
    switch ($acao) {
        case 'reenvio':
            Queue::fake();
            app(TenantManager::class)->set((int) $args['empresa_id']);
            app(PermissionRegistrar::class)->setPermissionsTeamId((int) $args['empresa_id']);

            $utilizador = User::withoutGlobalScopes()->findOrFail((int) $args['user_id']);
            $pedido = Request::create('/', 'POST');
            $pedido->setUserResolver(fn () => $utilizador);

            $resposta = app(CampanhaController::class)->enviarAgora($pedido, (int) $args['campanha_id']);

            $resultado = [
                'sucesso' => $resposta->getSession()->get('sucesso'),
                'erro' => $resposta->getSession()->get('erro'),
                'despachados' => Queue::pushed(DispararCampanhaJob::class)->count(),
            ];
            break;

        case 'emitir':
            // Limite 'max_faturas_mes' do plano (os processos filhos não herdam bindings do teste).
            if (array_key_exists('limite_faturas', $args)) {
                $limite = $args['limite_faturas'] === null ? null : (int) $args['limite_faturas'];
                app()->bind(LimitesDaEmpresa::class, fn () => new class($limite) implements LimitesDaEmpresa
                {
                    public function __construct(protected ?int $limite) {}

                    public function limite(Empresa $empresa, string $chave): ?int
                    {
                        return $chave === 'max_faturas_mes' ? $this->limite : null;
                    }
                });
            }

            $fatura = Fatura::withoutGlobalScopes()->findOrFail((int) $args['fatura_id']);
            $emitida = app(FaturaService::class)->emitir($fatura);

            $resultado = [
                'numero' => (int) $emitida->numero_sequencial,
                'documento' => (string) $emitida->numero_documento,
            ];
            break;

        case 'estudio_sinal':
        case 'estudio_saldo_final':
            app(TenantManager::class)->set((int) $args['empresa_id']);

            $projeto = ProjetoMusical::query()->findOrFail((int) $args['projeto_id']);
            $faturaEstudio = $acao === 'estudio_sinal'
                ? app(FaturacaoEstudioService::class)->gerarFaturaSinal($projeto)
                : app(FaturacaoEstudioService::class)->gerarFaturaSaldoFinal($projeto);

            $resultado = ['fatura_id' => (int) $faturaEstudio->id];
            break;

        case 'atelier_sinal':
        case 'atelier_fatura_final':
        case 'atelier_pagamento_final':
            app(TenantManager::class)->set((int) $args['empresa_id']);

            $pedido = Pedido::query()->findOrFail((int) $args['pedido_id']);
            $servicoPedido = app(PedidoFaturacaoService::class);

            if ($acao === 'atelier_sinal') {
                $resultado = ['recibo_id' => (int) $servicoPedido->registarSinal($pedido, (string) $args['valor'], (string) $args['meio'])->id];
            } elseif ($acao === 'atelier_fatura_final') {
                $resultado = ['fatura_id' => (int) $servicoPedido->gerarFaturaFinalRascunho($pedido)->id];
            } else {
                $resultado = ['recibo_id' => (int) $servicoPedido->registarPagamentoFinal($pedido, (string) $args['meio'])->id];
            }
            break;

        case 'confirmar_pagamento':
            $confirmado = app(PagamentoService::class)->confirmar(
                Pagamento::withoutGlobalScopes()->findOrFail((int) $args['pagamento_id']),
            );

            $resultado = ['estado' => (string) $confirmado->estado];
            break;

        case 'emitir_recibo':
            $recibo = app(ReciboService::class)->emitir(
                (int) $args['empresa_id'],
                (int) $args['cliente_id'],
                (string) $args['valor'],
                (string) $args['meio'],
                (int) $args['fatura_id'],
            );

            $resultado = ['recibo_id' => (int) $recibo->id];
            break;

        case 'emitir_nota':
            $nota = NotaCreditoDebito::withoutGlobalScopes()->findOrFail((int) $args['nota_id']);
            $emitida = app(NotaCreditoDebitoService::class)->emitir($nota);

            $resultado = ['nota_id' => (int) $emitida->id];
            break;

        case 'abater':
            $abatimento = app(AbatimentoAdiantamentoService::class)->abater(
                (int) $args['empresa_id'],
                (int) $args['fa_id'],
                (int) $args['ft_id'],
                (string) $args['valor'],
                (string) $args['chave'],
            );

            $resultado = ['abatimento_id' => (int) $abatimento['id']];
            break;

        case 'alocar_recibo':
            $linhas = app(ReciboAlocacaoService::class)->registar(
                (int) $args['empresa_id'],
                (int) $args['recibo_id'],
                $args['alocacoes'],
                (string) $args['chave'],
            );

            $resultado = [
                'linhas' => count($linhas),
                'ids' => array_map('intval', array_column($linhas, 'id')),
                'sequencias' => array_map('intval', array_column($linhas, 'sequencia')),
            ];
            break;

        default:
            throw new InvalidArgumentException("Ação desconhecida: {$acao}");
    }

    echo json_encode(['ok' => true] + $resultado, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e::class.': '.$e->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
}
