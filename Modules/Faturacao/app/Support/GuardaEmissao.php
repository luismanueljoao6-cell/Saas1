<?php

namespace Modules\Faturacao\Support;

use DomainException;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;

/**
 * Regra de negócio única: só se emitem faturas e recibos com subscrição
 * ativa. Vive aqui (e não nos controllers) para nenhum chamador a contornar.
 */
final class GuardaEmissao
{
    public static function garantir(int $empresaId): void
    {
        $empresa = Empresa::query()->find($empresaId);

        if (! $empresa) {
            throw new DomainException('Empresa inexistente.');
        }

        if (! $empresa->podeEmitirFaturas()) {
            throw EmissaoNaoPermitidaException::subscricaoInativa();
        }
    }

    /**
     * Limite comercial 'max_faturas_mes' do plano (null = sem limite).
     *
     * Chamar DENTRO da transação de emissão: o lock da linha da empresa
     * serializa emissões simultâneas, por isso duas faturas não consomem a
     * mesma última vaga do mês. Reemitir uma fatura já emitida não passa por
     * aqui (FaturaService::emitir devolve-a antes).
     */
    public static function garantirLimiteMensalFaturas(int $empresaId): void
    {
        $empresa = Empresa::query()->lockForUpdate()->find($empresaId);

        if (! $empresa) {
            throw new DomainException('Empresa inexistente.');
        }

        $limite = app(LimitesDaEmpresa::class)->limite($empresa, 'max_faturas_mes');

        if ($limite === null) {
            return;
        }

        $emitidas = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('estado', 'emitida')
            ->whereBetween('data_hora_sistema', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        if ($emitidas >= $limite) {
            throw EmissaoNaoPermitidaException::limiteMensalAtingido($limite);
        }
    }
}
