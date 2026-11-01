<?php

namespace Modules\EstudioMusica\Tests\Support;

use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Models\Serie;

/**
 * Cria documentos fiscais "já emitidos" diretamente na base de dados
 * (forceFill), sem passar pelo fluxo completo de emissão — esse está coberto
 * pelos testes do módulo Faturação; aqui só interessa o estado final das
 * colunas que o Estúdio lê.
 *
 * Os módulos não têm autoload-dev: os testes que usam este trait fazem
 * require_once deste ficheiro.
 */
trait CriaDocumentosFiscais
{
    /** @var array<string, int> */
    protected array $contadoresDocumentos = [];

    /** Chave RSA só para este processo de teste, gerada uma vez e apagada no fim — como nos testes do Faturação. */
    protected function prepararChaveFiscal(): void
    {
        static $caminho = null;

        if ($caminho === null) {
            $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($recurso, $pem);

            $caminho = sys_get_temp_dir().'/estudiomusica-teste-'.uniqid().'.pem';
            file_put_contents($caminho, $pem);
            register_shutdown_function(static fn () => @unlink($caminho));
        }

        config(['faturacao.chave_privada_path' => $caminho]);
    }

    /** Limite 'max_faturas_mes' do plano (null = sem limite) — a emissão recusa-o com EmissaoNaoPermitidaException. */
    protected function limitarFaturasPorMes(?int $limite): void
    {
        $this->app->bind(LimitesDaEmpresa::class, fn () => new class($limite) implements LimitesDaEmpresa
        {
            public function __construct(protected ?int $limite) {}

            public function limite(Empresa $empresa, string $chave): ?int
            {
                return $chave === 'max_faturas_mes' ? $this->limite : null;
            }
        });
    }

    protected function proximoNumeroDocumento(string $tipo): int
    {
        return $this->contadoresDocumentos[$tipo] = ($this->contadoresDocumentos[$tipo] ?? 0) + 1;
    }

    protected function serieDeTeste(string $tipo, int $empresaId): Serie
    {
        return Serie::query()->firstOrCreate(
            ['empresa_id' => $empresaId, 'tipo_documento' => $tipo, 'ano' => 2026, 'prefixo' => 'T'],
            ['ultimo_numero' => 0],
        );
    }

    protected function faturaEmitida(int $empresaId, int $clienteId, float $total, ?float $semIva = null): Fatura
    {
        $semIva ??= round($total / 1.14, 2);
        $numero = $this->proximoNumeroDocumento('FT');

        $fatura = new Fatura;
        $fatura->forceFill([
            'empresa_id' => $empresaId,
            'serie_id' => $this->serieDeTeste('FT', $empresaId)->id,
            'cliente_id' => $clienteId,
            'numero_sequencial' => $numero,
            'numero_documento' => "FT T2026/{$numero}",
            'estado' => 'emitida',
            'valor_total' => $total,
            'valor_sem_iva' => $semIva,
            'valor_iva' => round($total - $semIva, 2),
        ])->save();

        return $fatura;
    }

    protected function reciboEmitido(Fatura $fatura, float $valor): Recibo
    {
        $numero = $this->proximoNumeroDocumento('RC');

        $recibo = new Recibo;
        $recibo->forceFill([
            'empresa_id' => $fatura->empresa_id,
            'serie_id' => $this->serieDeTeste('RC', (int) $fatura->empresa_id)->id,
            'cliente_id' => $fatura->cliente_id,
            'fatura_id' => $fatura->id,
            'numero_sequencial' => $numero,
            'numero_documento' => "RC T2026/{$numero}",
            'estado' => 'emitido',
            'valor' => $valor,
            'meio_pagamento' => 'multicaixa',
        ])->save();

        return $recibo;
    }

    protected function notaCreditoEmitida(Fatura $fatura, float $total, ?float $semIva = null): NotaCreditoDebito
    {
        $semIva ??= round($total / 1.14, 2);
        $numero = $this->proximoNumeroDocumento('NC');

        $nota = new NotaCreditoDebito;
        $nota->forceFill([
            'empresa_id' => $fatura->empresa_id,
            'serie_id' => $this->serieDeTeste('NC', (int) $fatura->empresa_id)->id,
            'cliente_id' => $fatura->cliente_id,
            'fatura_id' => $fatura->id,
            'tipo' => 'credito',
            'motivo' => 'Correção de teste',
            'numero_sequencial' => $numero,
            'numero_documento' => "NC T2026/{$numero}",
            'estado' => 'emitida',
            'valor_total' => $total,
            'valor_sem_iva' => $semIva,
            'valor_iva' => round($total - $semIva, 2),
        ])->save();

        return $nota;
    }
}
