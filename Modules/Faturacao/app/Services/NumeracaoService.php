<?php

namespace Modules\Faturacao\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Serie;

/**
 * A lei exige numeração SEM LACUNAS por série/tipo/ano — dois pedidos de
 * emissão em simultâneo nunca podem "saltar" um número nem repeti-lo. A
 * garantia vem de `lockForUpdate()` dentro de uma transação: a segunda
 * transação fica bloqueada na leitura da linha da Serie até a primeira
 * terminar (commit ou rollback), nunca lê um valor desatualizado.
 *
 * IMPORTANTE: todos os métodos aqui recebem $empresaId explicitamente e
 * filtram por ele em cada query — por isso operam sempre dentro de
 * TenantManager::semTenant(). Depender também do tenant "ambiente"
 * (definido pelo middleware IdentificarTenant) seria redundante e frágil:
 * este serviço é chamado a partir de testes, jobs e, no futuro, possíveis
 * comandos artisan, nenhum dos quais passa necessariamente pelo
 * middleware — e a TenantScope do Core é fail-closed (bloqueia tudo sem
 * tenant definido), o que faria estas queries falharem em silêncio.
 */
class NumeracaoService
{
    public function __construct(protected TenantManager $tenantManager) {}

    /**
     * @return array{serie: Serie, numero_sequencial: int, numero_documento: string}
     */
    public function proximoNumero(int $empresaId, string $tipoDocumento, string $prefixo = 'A'): array
    {
        return $this->tenantManager->semTenant(function () use ($empresaId, $tipoDocumento, $prefixo) {
            return DB::transaction(function () use ($empresaId, $tipoDocumento, $prefixo) {
                $serie = $this->obterOuCriarSerie($empresaId, $tipoDocumento, $prefixo, comLock: true);

                $novoNumero = $serie->ultimo_numero + 1;
                $serie->update(['ultimo_numero' => $novoNumero]);

                return [
                    'serie' => $serie,
                    'numero_sequencial' => $novoNumero,
                    'numero_documento' => "{$tipoDocumento} {$prefixo}{$serie->ano}/{$novoNumero}",
                ];
            });
        });
    }

    /**
     * Encontra (ou cria, com segurança sob concorrência) a série do ano
     * corrente para este tipo de documento — usado tanto por
     * proximoNumero() como por quem só precisa de saber qual É a série
     * atual sem consumir um número (ex.: FaturaService ao criar um
     * rascunho). $comLock só interessa dentro de uma transação que vai a
     * seguir incrementar ultimo_numero; para leitura isolada, false chega.
     */
    public function obterOuCriarSerie(int $empresaId, string $tipoDocumento, string $prefixo = 'A', bool $comLock = false): Serie
    {
        return $this->tenantManager->semTenant(function () use ($empresaId, $tipoDocumento, $prefixo, $comLock) {
            $ano = now()->year;

            $query = Serie::query()
                ->where('empresa_id', $empresaId)
                ->where('tipo_documento', $tipoDocumento)
                ->where('ano', $ano)
                ->where('prefixo', $prefixo);

            $serie = $comLock ? $query->lockForUpdate()->first() : $query->first();

            return $serie ?? $this->criarSerieComSeguranca($empresaId, $tipoDocumento, $ano, $prefixo);
        });
    }

    /**
     * Duas transações podem chegar aqui em simultâneo para a primeira
     * emissão do ano de uma série — a unique constraint na tabela garante
     * que só uma consegue criar; a outra apanha a exceção e relê com lock.
     * Já corre dentro do semTenant() de quem chamou (obterOuCriarSerie).
     */
    protected function criarSerieComSeguranca(int $empresaId, string $tipoDocumento, int $ano, string $prefixo): Serie
    {
        try {
            return Serie::create([
                'empresa_id' => $empresaId,
                'tipo_documento' => $tipoDocumento,
                'ano' => $ano,
                'prefixo' => $prefixo,
                'ultimo_numero' => 0,
            ]);
        } catch (QueryException $e) {
            return Serie::query()
                ->where('empresa_id', $empresaId)
                ->where('tipo_documento', $tipoDocumento)
                ->where('ano', $ano)
                ->where('prefixo', $prefixo)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
