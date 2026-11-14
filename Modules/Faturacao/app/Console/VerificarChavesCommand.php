<?php

namespace Modules\Faturacao\Console;

use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Services\AssinaturaFiscalService;

/**
 * Auditoria das chaves de assinatura: cada empresa assina com a SUA chave
 * ({chaves_dir}/{NIF}.pem), RSA com o mínimo de bits, sem partilhar chaves entre
 * empresas, com a chave do software e a chave genérica à parte, e com permissões
 * fechadas. Procedimento de rotação: ver docs/CHAVES.md do módulo.
 */
class VerificarChavesCommand extends Command
{
    protected $signature = 'faturacao:verificar-chaves
        {--empresa= : ID da empresa (por omissão, todas)}';

    protected $description = 'Verifica as chaves de assinatura fiscal: uma por empresa (NIF), RSA com o mínimo de bits, permissões e sem chaves partilhadas';

    public function handle(AssinaturaFiscalService $assinatura): int
    {
        // Com a AGT ativa ou em produção, uma chave em falta é um problema; em desenvolvimento, só um aviso.
        $exigir = (bool) config('faturacao.agt.ativo') || app()->isProduction();

        /** @var array<int, array{0: string, 1: string}> $problemas */
        $problemas = [];
        /** @var array<int, array{0: string, 1: string}> $avisos */
        $avisos = [];
        /** @var array<string, string> $utilizadores impressão da chave pública => quem a usa */
        $utilizadores = [];
        $verificadas = 0;

        $empresaId = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;
        $empresas = Empresa::query()
            ->when($empresaId, fn ($q) => $q->whereKey($empresaId))
            ->orderBy('id')
            ->get(['id', 'nif']);

        foreach ($empresas as $empresa) {
            $rotulo = "empresa #{$empresa->id}";

            if (trim((string) $empresa->nif) === '') {
                $problemas[] = [$rotulo, 'sem NIF: não é possível localizar a chave'];

                continue;
            }

            $caminho = $assinatura->caminhoDaChaveDaEmpresa((int) $empresa->id);

            if (! is_file($caminho)) {
                $mensagem = 'chave em falta: '.$caminho;

                if ($exigir) {
                    $problemas[] = [$rotulo, $mensagem];
                } else {
                    $avisos[] = [$rotulo, $mensagem];
                }

                continue;
            }

            [$impressao, $erro] = $this->analisar($caminho);

            if ($erro !== null) {
                $problemas[] = [$rotulo, "chave inválida: {$erro}"];

                continue;
            }

            $verificadas++;

            if (isset($utilizadores[$impressao])) {
                $problemas[] = [$rotulo, "usa a mesma chave que {$utilizadores[$impressao]}"];
            } else {
                $utilizadores[$impressao] = $rotulo;
            }

            $permissoes = DIRECTORY_SEPARATOR === '/' ? (fileperms($caminho) & 0777) : 0;

            if (($permissoes & 0077) !== 0) {
                $avisos[] = [$rotulo, sprintf('permissões %o demasiado abertas no ficheiro da chave (usa chmod 600)', $permissoes)];
            }
        }

        $this->verificarChaveGenerica($utilizadores, $exigir, $problemas, $avisos);
        $this->verificarChaveDoSoftware($utilizadores, $problemas);
        $this->verificarArquivoHistorico($assinatura, $problemas, $empresaId);

        if ($avisos !== []) {
            $this->table(['Âmbito', 'Aviso'], $avisos);
        }

        if ($problemas !== []) {
            $this->table(['Âmbito', 'Problema'], $problemas);
            $this->error(count($problemas).' problema(s) nas chaves de assinatura (ver docs/CHAVES.md).');

            return self::FAILURE;
        }

        $this->info("{$verificadas} chave(s) de empresa verificadas: RSA, sem partilha entre empresas.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $utilizadores
     * @param  array<int, array{0: string, 1: string}>  $problemas
     * @param  array<int, array{0: string, 1: string}>  $avisos
     */
    protected function verificarChaveGenerica(array &$utilizadores, bool $exigir, array &$problemas, array &$avisos): void
    {
        $generica = (string) config('faturacao.chave_privada_path');

        if ($generica === '' || ! is_file($generica)) {
            return;
        }

        [$impressao, $erro] = $this->analisar($generica);

        if ($erro !== null) {
            if ($exigir) {
                $problemas[] = ['chave genérica', "chave inválida: {$erro}"];
            } else {
                $avisos[] = ['chave genérica', "chave inválida: {$erro}"];
            }

            return;
        }

        if ($impressao !== null && isset($utilizadores[$impressao])) {
            $problemas[] = ['chave genérica', "é igual à chave de {$utilizadores[$impressao]}: cada empresa tem de ter a sua própria chave"];
        }

        if ($exigir) {
            $avisos[] = ['chave genérica', 'o ficheiro '.basename($generica).' existe no servidor; com a AGT ativa ou em produção não é usado — remove-o'];
        }
    }

    /**
     * @param  array<string, string>  $utilizadores
     * @param  array<int, array{0: string, 1: string}>  $problemas
     */
    protected function verificarChaveDoSoftware(array $utilizadores, array &$problemas): void
    {
        if (! config('faturacao.agt.ativo')) {
            return;
        }

        $caminho = (string) config('faturacao.agt.software.chave_privada_path');

        if ($caminho === '' || ! is_file($caminho)) {
            $problemas[] = ['chave do software', 'chave privada do software em falta (FATURACAO_AGT_CHAVE_SOFTWARE)'];

            return;
        }

        [$impressao, $erro] = $this->analisar($caminho);

        if ($erro !== null) {
            $problemas[] = ['chave do software', "chave inválida: {$erro}"];
        } elseif (isset($utilizadores[$impressao])) {
            $problemas[] = ['chave do software', "é igual à chave de {$utilizadores[$impressao]}: o software e os emissores têm chaves distintas"];
        }
    }

    /**
     * Verifica se todos os documentos finais têm uma chave pública histórica
     * íntegra e se a chave instalada não foi trocada sem incrementar a versão.
     *
     * @param  array<int, array{0: string, 1: string}>  $problemas
     */
    protected function verificarArquivoHistorico(AssinaturaFiscalService $assinatura, array &$problemas, ?int $empresaId = null): void
    {
        if (! Schema::hasTable('faturacao_chaves_publicas')) {
            $problemas[] = ['arquivo histórico', 'a tabela faturacao_chaves_publicas não existe; executa php artisan migrate'];

            return;
        }

        $tabelas = [
            'faturas' => ['emitida'],
            'notas_credito_debito' => ['emitida'],
            'recibos' => ['emitido', 'anulado'],
        ];

        foreach ($tabelas as $tabela => $estados) {
            if (! Schema::hasTable($tabela)) {
                $problemas[] = [$tabela, 'a tabela não existe; confirma as migrations do módulo Faturação'];

                continue;
            }

            $consulta = DB::table($tabela)->whereIn('estado', $estados);

            if ($empresaId !== null) {
                $consulta->where('empresa_id', $empresaId);
            }

            $semVersao = (clone $consulta)->whereNull('chave_versao')->count();

            if ($semVersao > 0) {
                $problemas[] = [$tabela, "{$semVersao} documento(s) final(is) sem versão de chave de assinatura"];
            }

            $documentos = (clone $consulta)
                ->whereNotNull('chave_versao')
                ->select('empresa_id', 'chave_versao')
                ->distinct()
                ->get();

            foreach ($documentos as $documento) {
                $empresaId = (int) $documento->empresa_id;
                $versao = (int) $documento->chave_versao;

                if ($assinatura->chavePublicaDaVersao($empresaId, $versao) === null) {
                    $problemas[] = [
                        "empresa #{$empresaId}",
                        "há documentos finais em {$tabela} na versão {$versao} sem chave pública histórica íntegra",
                    ];
                }
            }
        }

        $versaoAtual = (int) config('faturacao.chave_versao', 1);

        foreach (Empresa::query()->when($empresaId, fn ($q) => $q->whereKey($empresaId))->orderBy('id')->get(['id', 'nif']) as $empresa) {
            try {
                $chaveInstalada = $assinatura->chavePublicaDaEmpresa((int) $empresa->id);
            } catch (AssinaturaFiscalException|InvalidArgumentException) {
                continue; // O erro já foi reportado na verificação do ficheiro privado.
            }

            $chaveArquivada = $assinatura->chavePublicaDaVersao((int) $empresa->id, $versaoAtual);

            if ($chaveInstalada === null || $chaveArquivada === null) {
                continue;
            }

            $fingerprintInstalado = hash('sha256', preg_replace('/\\s+/', '', $chaveInstalada) ?: $chaveInstalada);
            $fingerprintArquivado = hash('sha256', preg_replace('/\\s+/', '', $chaveArquivada) ?: $chaveArquivada);

            if (! hash_equals($fingerprintArquivado, $fingerprintInstalado)) {
                $problemas[] = [
                    "empresa #{$empresa->id}",
                    "a chave privada instalada difere da chave arquivada na versão {$versaoAtual}; incrementa FATURACAO_CHAVE_VERSAO antes de emitir",
                ];
            }
        }
    }

    /** @return array{0: ?string, 1: ?string} [impressão da chave pública, erro] */
    protected function analisar(string $caminho): array
    {
        $chave = openssl_pkey_get_private((string) file_get_contents($caminho));

        if (! $chave) {
            return [null, 'não é uma chave privada PEM válida'];
        }

        $detalhes = openssl_pkey_get_details($chave) ?: [];
        $minimo = (int) config('faturacao.chave_minima_bits', 2048);

        if (($detalhes['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return [null, 'não é uma chave RSA'];
        }

        if ((int) ($detalhes['bits'] ?? 0) < $minimo) {
            return [null, 'tem '.(int) ($detalhes['bits'] ?? 0)." bits (mínimo {$minimo})"];
        }

        return [hash('sha256', (string) ($detalhes['key'] ?? '')), null];
    }
}
