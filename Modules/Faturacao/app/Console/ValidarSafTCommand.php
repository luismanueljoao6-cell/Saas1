<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Modules\Faturacao\Services\SafTValidadorXsd;

/**
 * Corre as duas camadas de validação do SAF-T (AO) sem publicar nada:
 *   1. regras fiscais e coerência dos documentos (SafTPreValidacao);
 *   2. conformidade com o XSD oficial (SafTValidadorXsd), se o XSD existir.
 * Sai com erro se alguma falhar OU se o XSD oficial não estiver disponível:
 * "sem erros" sem XSD não é "validado".
 */
class ValidarSafTCommand extends Command
{
    protected $signature = 'faturacao:saft-validar
        {empresa : ID da empresa}
        {inicio : Data de início (AAAA-MM-DD)}
        {fim : Data de fim (AAAA-MM-DD)}
        {--xsd= : Caminho do XSD oficial (por omissão, faturacao.saft_xsd_path)}
        {--guardar= : Guarda o XML gerado neste caminho, mesmo que inválido (diagnóstico)}';

    protected $description = 'Valida o SAF-T (AO) de uma empresa e período: regras fiscais + XSD oficial (não publica nada)';

    public function handle(SafTExportService $exportador, SafTPreValidacao $regras, SafTValidadorXsd $validador): int
    {
        $inicio = $this->data((string) $this->argument('inicio'));
        $fim = $this->data((string) $this->argument('fim'));

        if ($inicio === null || $fim === null) {
            $this->error('Datas inválidas: usa o formato AAAA-MM-DD.');

            return self::FAILURE;
        }

        $fim = $fim->endOfDay();

        if (($erroPeriodo = $exportador->validarPeriodo($inicio, $fim)) !== null) {
            $this->error($erroPeriodo);

            return self::FAILURE;
        }

        $empresa = Empresa::query()->find((int) $this->argument('empresa'));

        if ($empresa === null) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }

        if ($this->option('xsd')) {
            config(['faturacao.saft_xsd_path' => (string) $this->option('xsd')]);
        }

        $this->line("Empresa #{$empresa->id}, período {$inicio->toDateString()} a {$fim->toDateString()}");

        $falhou = false;

        $errosRegras = $regras->validar($empresa, $inicio, $fim);

        if ($errosRegras !== []) {
            $falhou = true;
            $this->table(['#', 'Regras fiscais / coerência'], $this->linhas($errosRegras));
        } else {
            $this->info('Regras fiscais e coerência dos documentos: sem problemas.');
        }

        try {
            $xml = $exportador->gerar($empresa, $inicio, $fim);
        } catch (\RuntimeException $e) {
            $this->error('Não foi possível gerar o ficheiro: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('guardar')) {
            file_put_contents((string) $this->option('guardar'), $xml);
            $this->line('XML guardado em '.$this->option('guardar'));
        }

        if (! $validador->disponivel()) {
            $this->warn('XSD oficial não encontrado em "'.$validador->caminhoXsd().'": a conformidade estrutural NÃO foi verificada.');

            return self::FAILURE;
        }

        $this->line('XSD: '.$validador->caminhoXsd().' (targetNamespace: '.($validador->namespaceAlvo() ?? 'nenhum').')');

        $errosXsd = $validador->validar($xml);

        if ($errosXsd !== []) {
            $falhou = true;
            $this->table(['#', 'Erros do XSD'], $this->linhas($errosXsd));
        } else {
            $this->info('Conforme ao XSD indicado.');
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }

    protected function data(string $texto): ?Carbon
    {
        try {
            $data = Carbon::createFromFormat('Y-m-d', $texto);
        } catch (\Throwable) {
            return null;
        }

        // Rejeita datas que o Carbon "corrige" em silêncio (ex.: 2026-02-31).
        return $data && $data->format('Y-m-d') === $texto ? $data->startOfDay() : null;
    }

    /**
     * @param  list<string>  $erros
     * @return list<array{0: int, 1: string}>
     */
    protected function linhas(array $erros): array
    {
        $linhas = [];

        foreach (array_values($erros) as $i => $erro) {
            $linhas[] = [$i + 1, $erro];
        }

        return $linhas;
    }
}
