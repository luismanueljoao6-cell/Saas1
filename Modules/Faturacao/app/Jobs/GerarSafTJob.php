<?php

namespace Modules\Faturacao\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTValidadorXsd;
use Throwable;

/**
 * Corre sempre em fila. ATENÇÃO: DB_QUEUE_RETRY_AFTER tem de ser MAIOR que
 * $timeout (ver .env.example), senão o job é reentregue enquanto ainda corre.
 *
 * "Job terminou" não é "ficheiro fiscalmente válido". Cada geração fica com um
 * estado (ficheiro .json em `meta/`):
 *   - validado_xsd         conforme ao XSD oficial → publicado
 *   - gerado_sem_validacao XSD oficial ausente → publicado só fora de produção
 *   - rejeitado_xsd        não conforme ao XSD → NÃO publicado (pasta rejeitados/)
 * A versão publicada anterior do mesmo período vai para historico/ em vez de
 * ser substituída.
 */
class GerarSafTJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const ESTADO_VALIDADO = 'validado_xsd';

    public const ESTADO_SEM_VALIDACAO = 'gerado_sem_validacao';

    public const ESTADO_REJEITADO = 'rejeitado_xsd';

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        protected Empresa $empresa,
        protected string $dataInicio,
        protected string $dataFim,
    ) {}

    public function uniqueId(): string
    {
        return "saft:{$this->empresa->id}:{$this->dataInicio}:{$this->dataFim}";
    }

    public function handle(SafTExportService $safTExportService, SafTValidadorXsd $validador): void
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $this->dataInicio)->startOfDay();
        $fim = Carbon::createFromFormat('Y-m-d', $this->dataFim)->endOfDay();

        $xml = $safTExportService->gerar($this->empresa, $inicio, $fim);

        $erros = [];

        if ($validador->disponivel()) {
            $erros = $validador->validar($xml);
            $estado = $erros === [] ? self::ESTADO_VALIDADO : self::ESTADO_REJEITADO;
        } else {
            $estado = self::ESTADO_SEM_VALIDACAO;
        }

        $publicar = $estado === self::ESTADO_VALIDADO
            || ($estado === self::ESTADO_SEM_VALIDACAO && ! app()->isProduction());

        $disco = Storage::disk('local');
        $dir = "faturacao/saft/{$this->empresa->id}";
        $nome = "SAFT_{$inicio->toDateString()}_{$fim->toDateString()}";
        $agora = now()->format('Ymd_His');

        if ($publicar) {
            // Guarda a versão anterior do mesmo período em vez de a substituir.
            $versoes = [
                ["{$dir}/{$nome}.xml", "{$dir}/historico/{$nome}_{$agora}.xml"],
                ["{$dir}/meta/{$nome}.json", "{$dir}/historico/{$nome}_{$agora}.json"],
            ];

            foreach ($versoes as [$de, $para]) {
                if ($disco->exists($de)) {
                    $disco->move($de, $para);
                }
            }

            $caminho = "{$dir}/{$nome}.xml";
            $caminhoMeta = "{$dir}/meta/{$nome}.json";
        } else {
            $caminho = "{$dir}/rejeitados/{$nome}_{$agora}.xml";
            $caminhoMeta = "{$dir}/rejeitados/{$nome}_{$agora}.json";
        }

        $disco->put($caminho, $xml);
        $disco->put($caminhoMeta, (string) json_encode([
            'empresa_id' => $this->empresa->id,
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()],
            'gerado_em' => now()->toIso8601String(),
            'estado' => $estado,
            'sha256' => hash('sha256', $xml),
            'tamanho_bytes' => strlen($xml),
            'numero_certificado' => (string) config('faturacao.saft_numero_certificado', '0'),
            'xsd' => $validador->disponivel() ? basename($validador->caminhoXsd()) : null,
            'erros' => $erros,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Log::info('SAF-T (AO) gerado', [
            'empresa_id' => $this->empresa->id,
            'caminho' => $caminho,
            'estado' => $estado,
            'tamanho_bytes' => strlen($xml),
        ]);

        if ($estado === self::ESTADO_REJEITADO) {
            throw new \RuntimeException(
                'O SAF-T gerado não é conforme ao XSD oficial ('.count($erros)." erro(s)); guardado em {$caminho} só para diagnóstico."
            );
        }

        if (! $publicar) {
            throw new \RuntimeException(
                "Sem o XSD oficial em \"{$validador->caminhoXsd()}\" o SAF-T não pode ser marcado como pronto em produção; guardado em {$caminho} só para diagnóstico."
            );
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Falha ao gerar SAF-T (AO)', [
            'empresa_id' => $this->empresa->id,
            'periodo' => "{$this->dataInicio} a {$this->dataFim}",
            'erro' => $e->getMessage(),
        ]);
    }
}
