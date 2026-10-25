<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Throwable;

/**
 * Auditoria independente: para cada série confirma numeração sem saltos (e dentro do
 * intervalo autorizado pela AGT), número do documento coerente com o nº sequencial,
 * hash_anterior == hash do documento anterior, assinatura RSA válida e conteúdo
 * assinado igual ao persistido. Com --agt confirma também a correspondência com a AGT.
 *
 * Chave pública, por ordem: --chave-publica (todas as empresas); a derivada da chave
 * privada da própria empresa ({NIF}.pem, só neste servidor); o ficheiro por omissão
 * faturacao.chave_publica_path (storage/app/faturacao/chave-publica.pem). Documentos assinados com uma versão antiga
 * da chave precisam da chave pública dessa versão (--chave-publica).
 */
class VerificarCadeiaFiscalCommand extends Command
{
    protected $signature = 'faturacao:verificar-cadeia
        {--empresa= : ID da empresa (por omissão, todas)}
        {--chave-publica= : Caminho da chave pública PEM (por omissão, a de cada empresa)}
        {--agt : Confirma também a correspondência com a AGT (só o estado "válido" é confirmação fiscal)}';

    protected $description = 'Verifica numeração, cadeia de hashes e assinaturas dos documentos fiscais emitidos';

    public function handle(TenantManager $tenantManager, AssinaturaFiscalService $assinatura): int
    {
        $manual = $this->option('chave-publica');

        if ($manual && ! is_file($manual)) {
            $this->error("Chave pública não encontrada em {$manual}. Usa --chave-publica=.");

            return self::FAILURE;
        }

        $padrao = (string) config('faturacao.chave_publica_path');
        $comAgt = (bool) $this->option('agt');
        $empresaId = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;

        $problemas = [];
        $avisos = [];
        $total = 0;
        /** @var array<int, string|false> $chaves PEM da chave pública por empresa (false: não há) */
        $chaves = [];
        /** @var array<int, Serie|null> $series */
        $series = [];

        $chavePublicaDe = function (int $empresa) use (&$chaves, $manual, $padrao, $assinatura, &$problemas): string|false {
            if (array_key_exists($empresa, $chaves)) {
                return $chaves[$empresa];
            }

            try {
                $pem = $manual
                    ? (string) file_get_contents($manual)
                    : ($assinatura->chavePublicaDaEmpresa($empresa) ?? (is_file($padrao) ? (string) file_get_contents($padrao) : false));
            } catch (Throwable $e) {
                $pem = false;
            }

            if ($pem === false || $pem === '') {
                $problemas[] = ["empresa #{$empresa}", '-', '-', 'sem chave pública para verificar as assinaturas (chave da empresa em falta; usa --chave-publica=)'];
                $pem = false;
            }

            return $chaves[$empresa] = $pem;
        };

        $tenantManager->semTenant(function () use ($empresaId, $assinatura, $comAgt, $chavePublicaDe, &$series, &$problemas, &$avisos, &$total) {
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

                    $serie = $series[$serieAtual] ??= Serie::query()->find($serieAtual);

                    if ($numero !== $esperado) {
                        $problemas[] = [$nome, $serieAtual, $numero, "salto de numeração (esperado {$esperado})"];
                    }

                    if (! str_ends_with((string) $doc->numero_documento, '/'.$numero) && ! str_ends_with((string) $doc->numero_documento, (string) $numero)) {
                        $problemas[] = [$nome, $serieAtual, $numero, 'número do documento não corresponde ao nº sequencial'];
                    }

                    if ($serie?->agt_codigo !== null
                        && $serie->ultimo_numero_autorizado !== null
                        && $numero > (int) $serie->ultimo_numero_autorizado) {
                        $problemas[] = [$nome, $serieAtual, $numero, "fora do intervalo autorizado pela AGT (até {$serie->ultimo_numero_autorizado})"];
                    }

                    if (($doc->hash_anterior ?? null) !== $hashAnterior) {
                        $problemas[] = [$nome, $serieAtual, $numero, 'hash_anterior não coincide com o documento anterior'];
                    }

                    $pem = $chavePublicaDe((int) $doc->empresa_id);

                    if ($pem !== false) {
                        try {
                            if (! $assinatura->verificarComPem($doc->construirStringParaAssinar(), $doc->hash, $pem)) {
                                $problemas[] = [$nome, $serieAtual, $numero, 'assinatura RSA inválida'];
                            }
                        } catch (Throwable $e) {
                            $problemas[] = [$nome, $serieAtual, $numero, 'erro ao verificar: '.$e->getMessage()];
                        }
                    }

                    if ($comAgt) {
                        $submissao = AgtSubmissao::query()
                            ->where('documento_type', $modelo)
                            ->where('documento_id', $doc->getKey())
                            ->first();

                        if (! $submissao) {
                            $problemas[] = [$nome, $serieAtual, $numero, 'sem registo de submissão à AGT'];
                        } elseif (! $submissao->confirmadoFiscalmente()) {
                            $avisos[] = [$nome, $serieAtual, $numero, 'AGT: '.$submissao->rotulo()];
                        }
                    }

                    $esperado = $numero + 1;
                    $hashAnterior = $doc->hash;
                }
            }
        });

        if ($avisos !== []) {
            $this->table(['Tipo', 'Série', 'Nº', 'Aviso'], $avisos);
        }

        if ($problemas !== []) {
            $this->table(['Tipo', 'Série', 'Nº', 'Problema'], $problemas);
            $this->error(count($problemas)." problema(s) em {$total} documento(s).");

            return self::FAILURE;
        }

        $this->info("{$total} documento(s) verificados: numeração, cadeia de hashes e assinaturas íntegras.");

        return self::SUCCESS;
    }
}
