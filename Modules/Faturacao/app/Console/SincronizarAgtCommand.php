<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtSubmissaoService;

/**
 * Envia à AGT os documentos pendentes e consulta o estado dos já enviados.
 * Pensado para correr de minuto a minuto (agendado em routes/console.php).
 */
class SincronizarAgtCommand extends Command
{
    protected $signature = 'faturacao:agt-sincronizar {--empresa= : Limita a uma empresa (ID)}';

    protected $description = 'Envia à AGT os documentos pendentes (registarFactura) e consulta o estado dos enviados (obterEstado)';

    public function handle(AgtSubmissaoService $servico): int
    {
        $empresa = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;

        try {
            $envio = $servico->enviarPendentes($empresa);
            $estado = $servico->consultarEstados($empresa);
        } catch (AgtException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Envio: {$envio['enviados']} enviados, {$envio['rejeitados']} rejeitados, {$envio['erros']} com erro.");
        $this->info("Estado: {$estado['validos']} válidos, {$estado['invalidos']} inválidos, {$estado['erros']} com erro, {$estado['pendentes']} ainda em processamento.");

        return self::SUCCESS;
    }
}
