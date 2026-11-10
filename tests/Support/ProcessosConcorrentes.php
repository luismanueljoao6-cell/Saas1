<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Corre várias ações em PROCESSOS PHP separados, cada um com a sua ligação à base
 * de dados, todos libertados pela mesma barreira de tempo. É a diferença para
 * "dois pedidos seguidos no mesmo processo", que não prova concorrência.
 */
trait ProcessosConcorrentes
{
    /** Chamar ANTES de parent::setUp() para nem arrancar a aplicação quando não é para correr. */
    protected function exigirOptInConcorrenciaReal(): void
    {
        if (getenv('SAAS1_CONCORRENCIA_REAL') !== '1') {
            $this->markTestSkipped('Concorrência real: define SAAS1_CONCORRENCIA_REAL=1 (MySQL descartável).');
        }
    }

    /** Chamar DEPOIS de parent::setUp(). */
    protected function exigirMysqlDeTestes(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponível.');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A concorrência real só se prova em MySQL (SQLite serializa as escritas).');
        }

        $base = (string) DB::connection()->getDatabaseName();

        if (! str_contains(strtolower($base), 'test')) {
            $this->markTestSkipped("Recuso correr na base de dados '{$base}': o nome tem de conter 'test'.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $argumentos  um elemento por processo
     * @param  array<string, string>  $ambiente  variáveis de ambiente extra para os processos
     * @return list<array<string, mixed>> uma resposta por processo (ok=false se o processo falhou)
     */
    protected function correrEmParalelo(string $acao, array $argumentos, array $ambiente = []): array
    {
        // Margem para todos os processos arrancarem a aplicação antes da barreira.
        $inicio = microtime(true) + 4.0;
        $env = array_merge(getenv(), $ambiente);
        $processos = [];

        foreach ($argumentos as $a) {
            $comando = [
                PHP_BINARY,
                base_path('tests/Support/concorrencia_worker.php'),
                $acao,
                sprintf('%.6F', $inicio),
                json_encode($a, JSON_THROW_ON_ERROR),
            ];

            $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($processo, 'Não foi possível lançar o processo worker.');
            $processos[] = [$processo, $pipes];
        }

        $resultados = [];

        foreach ($processos as [$processo, $pipes]) {
            $saida = (string) stream_get_contents($pipes[1]);
            $erro = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($processo);

            $linhas = array_values(array_filter(array_map('trim', explode("\n", $saida)), 'strlen'));
            $json = $linhas === [] ? null : json_decode((string) end($linhas), true);

            $resultados[] = is_array($json)
                ? $json
                : ['ok' => false, 'erro' => "Saída inválida do worker. stdout: {$saida} stderr: {$erro}"];
        }

        return $resultados;
    }
}
