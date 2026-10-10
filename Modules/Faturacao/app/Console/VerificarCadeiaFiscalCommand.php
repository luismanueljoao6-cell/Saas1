<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Throwable;

/**
 * Auditoria independente: para cada série confirma numeração sem saltos,
 * hash_anterior == hash do documento anterior e assinatura RSA válida.
 * Documentos assinados com uma versão antiga da chave precisam da chave
 * pública dessa versão (--chave-publica).
 */
class VerificarCadeiaFiscalCommand extends Command
{
    protected $signature = 'faturacao:verificar-cadeia
        {--empresa= : ID da empresa (por omissão, todas)}
        {--chave-publica= : Caminho da chave pública PEM}';

    protected $description = 'Verifica numeração, cadeia de hashes e assinaturas dos documentos fiscais emitidos';

    public function handle(TenantManager $tenantManager, AssinaturaFiscalService $assinatura): int
    {
        $chavePublica = $this->option('chave-publica') ?: storage_path('app/faturacao/chave-publica.pem');

        if (! is_file($chavePublica)) {
            $this->error("Chave pública não encontrada em {$chavePublica}. Usa --chave-publica=.");

            return self::FAILURE;
        }

        $empresaId = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;
        $problemas = [];
        $total = 0;

        $tenantManager->semTenant(function () use ($empresaId, $assinatura, $chavePublica, &$problemas, &$total) {
            foreach ([Fatura::class, NotaCreditoDebito::class, Recibo::class] as $modelo) {
                $consulta = $modelo::query()
                    ->whereNotNull('numero_sequencial')
                    ->whereNotNull('hash')
                    ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
                    ->orderBy('serie_id')
                    ->orderBy('numero_sequencial');

                $serieAtual = null;
                $esperado = 1;
                $hashAnterior = null;

                foreach ($consulta->cursor() as $doc) {
                    $total++;
                    $nome = class_basename($modelo);
                    $numero = (int) $doc->numero_sequencial;

                    if ($serieAtual !== (int) $doc->serie_id) {
                        $serieAtual = (int) $doc->serie_id;
                        $esperado = 1;
                        $hashAnterior = null;
                    }

                    if ($numero !== $esperado) {
                        $problemas[] = [$nome, $serieAtual, $numero, "salto de numeração (esperado {$esperado})"];
                    }

                    if (($doc->hash_anterior ?? null) !== $hashAnterior) {
                        $problemas[] = [$nome, $serieAtual, $numero, 'hash_anterior não coincide com o documento anterior'];
                    }

                    try {
                        if (! $assinatura->verificar($doc->construirStringParaAssinar(), $doc->hash, $chavePublica)) {
                            $problemas[] = [$nome, $serieAtual, $numero, 'assinatura RSA inválida'];
                        }
                    } catch (Throwable $e) {
                        $problemas[] = [$nome, $serieAtual, $numero, 'erro ao verificar: '.$e->getMessage()];
                    }

                    $esperado = $numero + 1;
                    $hashAnterior = $doc->hash;
                }
            }
        });

        if ($problemas !== []) {
            $this->table(['Tipo', 'Série', 'Nº', 'Problema'], $problemas);
            $this->error(count($problemas)." problema(s) em {$total} documento(s).");

            return self::FAILURE;
        }

        $this->info("{$total} documento(s) verificados: numeração, cadeia de hashes e assinaturas íntegras.");

        return self::SUCCESS;
    }
}
