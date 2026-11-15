<?php

namespace Modules\Faturacao\Support;

use DomainException;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Fatura;

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

        self::garantirComunicacaoAgtEmDia($empresaId);
    }

    /**
     * Art. 17.º n.º 5 do Regime Jurídico das Facturas (DP 71/25): em caso de falha de comunicação
     * superior a 60 dias, o sistema bloqueia a emissão de novos documentos. Só conta o que a AGT
     * ainda não recebeu/aceitou por falha nossa ou de rede (pendente, erro técnico, a aguardar
     * intervenção): "inválido"/"rejeitado" são respostas da AGT, ou seja, a comunicação funcionou.
     */
    public static function garantirComunicacaoAgtEmDia(int $empresaId): void
    {
        if (! config('faturacao.agt.ativo')) {
            return;
        }

        $dias = (int) config('faturacao.agt.bloqueio_dias_sem_comunicacao', 60);

        if ($dias <= 0) {
            return;
        }

        $emFalta = AgtSubmissao::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('estado', [AgtSubmissao::PENDENTE, AgtSubmissao::ERRO, AgtSubmissao::AGUARDA_INTERVENCAO])
            ->where('created_at', '<', now()->subDays($dias))
            ->exists();

        if ($emFalta) {
            throw EmissaoNaoPermitidaException::comunicacaoAgtEmFalta($dias);
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
