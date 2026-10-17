<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Services\ConfiguracaoPagamentoValidator;

/**
 *   php artisan subscricoes:verificar-configuracao
 *
 * Código de saída 1 se a configuração impedir cobranças corretas.
 * Corre também diariamente no scheduler (ver SubscricoesServiceProvider).
 */
class VerificarConfiguracaoCommand extends Command
{
    protected $signature = 'subscricoes:verificar-configuracao';

    protected $description = 'Valida a configuração do gateway de pagamento (chave, entidade, sandbox vs produção)';

    public function handle(ConfiguracaoPagamentoValidator $validador): int
    {
        $identificador = (string) config('subscricoes.gateway');
        $config = (array) config("subscricoes.gateways.{$identificador}", []);

        $resultado = $validador->validar($config, app()->isProduction());

        foreach ($resultado['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        if ($resultado['erros'] === []) {
            $this->info("Configuração do gateway '{$identificador}' válida.");

            return self::SUCCESS;
        }

        foreach ($resultado['erros'] as $erro) {
            $this->error($erro);
        }

        Log::critical('Configuração de pagamento inválida', [
            'gateway' => $identificador,
            'erros' => $resultado['erros'],
        ]);

        return self::FAILURE;
    }
}
