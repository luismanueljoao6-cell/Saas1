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
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';

$acao = (string) ($argv[1] ?? '');
$barreira = (float) ($argv[2] ?? 0);
$args = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];

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
            $fatura = Fatura::withoutGlobalScopes()->findOrFail((int) $args['fatura_id']);
            $emitida = app(FaturaService::class)->emitir($fatura);

            $resultado = [
                'numero' => (int) $emitida->numero_sequencial,
                'documento' => (string) $emitida->numero_documento,
            ];
            break;

        default:
            throw new InvalidArgumentException("Ação desconhecida: {$acao}");
    }

    echo json_encode(['ok' => true] + $resultado, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e::class.': '.$e->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
}
