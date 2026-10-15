<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtSerieService;
use Throwable;

/**
 * Pede à AGT uma série de numeração (ou a sua extensão) para uma empresa.
 */
class SolicitarSerieAgtCommand extends Command
{
    protected $signature = 'faturacao:agt-solicitar-serie
        {empresa : ID da empresa}
        {tipo : Tipo de documento (FT, NC, ND, RC)}
        {--ano= : Ano da série (por omissão, o ano atual)}
        {--estabelecimento= : Código do estabelecimento (por omissão, SEDE)}
        {--contingencia=N : N (regime normal) ou C (contingência)}';

    protected $description = 'Solicita à AGT uma série de numeração de facturas electrónicas e guarda o intervalo autorizado';

    public function handle(AgtSerieService $servico): int
    {
        $empresa = Empresa::query()->find((int) $this->argument('empresa'));

        if (! $empresa) {
            $this->error('Empresa inexistente.');

            return self::FAILURE;
        }

        try {
            $serie = $servico->solicitar(
                $empresa,
                strtoupper((string) $this->argument('tipo')),
                $this->option('ano') !== null ? (int) $this->option('ano') : null,
                $this->option('estabelecimento') ?: null,
                strtoupper((string) $this->option('contingencia')),
            );
        } catch (AgtException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Falha ao solicitar a série: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Série {$serie->agt_codigo} ({$serie->tipo_documento}/{$serie->ano}) autorizada até ao nº {$serie->ultimo_numero_autorizado}.");

        return self::SUCCESS;
    }
}
