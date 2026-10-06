<?php

namespace Modules\EstudioMusica\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\CampanhaEnviada;
use Modules\EstudioMusica\Models\CupomDesconto;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\Faturacao\Models\Cliente;

/**
 * "Marketing Automático & CRM para Músicos" (secção F). As duas
 * automações com um gatilho claro e verificável (aniversário do artista;
 * 30 dias após um projeto concluído) correm sozinhas via Jobs agendados —
 * ver Jobs\EnviarCampanhaAniversarioJob e Jobs\EnviarFollowUpPosLancamentoJob.
 *
 * "Campanhas para Datas Comemorativas" fica de fora desse agendamento
 * automático de propósito: o requisito dá só exemplos de ocasiões (Dia da
 * Música, Fim de Ano...), não datas nem regras concretas para o sistema
 * decidir sozinho quando disparar — inventar esse calendário seria
 * ultrapassar o que foi pedido. enviarCampanhaGenerica() dá à Receção/
 * Administrador um envio em massa, disparado manualmente quando quiserem
 * lançar uma dessas campanhas, com a mesma proteção de idempotência.
 *
 * CampanhaEnviada é sempre a última escrita de cada envio, nunca a
 * primeira — se o envio da notificação falhar a meio, é preferível
 * tentar de novo amanhã do que marcar como "enviada" uma campanha que
 * não saiu.
 */
class CampanhaMarketingService
{
    public function __construct(protected NotificacaoEstudioService $notificacao)
    {
    }

    /**
     * Corre para TODAS as empresas — ver Jobs\EnviarCampanhaAniversarioJob
     * para o porquê do bypass via TenantManager.
     */
    public function enviarAniversarios(TenantManager $tenantManager): int
    {
        $enviados = 0;
        $hoje = now();

        $tenantManager->semTenant(function () use ($hoje, &$enviados): void {
            Cliente::query()
                ->whereNotNull('data_nascimento')
                ->chunkById(100, function ($clientes) use ($hoje, &$enviados): void {
                    foreach ($clientes as $cliente) {
                        if (! Carbon::parse($cliente->data_nascimento)->isBirthday($hoje)) {
                            continue;
                        }

                        if ($this->jaEnviadaEsteAno($cliente, 'aniversario', (string) $hoje->year)) {
                            continue;
                        }

                        $cupom = $this->gerarCupomAniversario($cliente);
                        $nome = $cliente->nome_artistico ?: $cliente->nome;

                        $this->notificacao->enviar(
                            $cliente,
                            'Parabéns!',
                            "Parabéns pelo teu dia, {$nome}! Ganhaste {$cupom->percentual_desconto}% de desconto em horas de estúdio, válido até {$cupom->valido_ate->format('d/m/Y')} — código {$cupom->codigo}."
                        );

                        $this->registarEnvio($cliente, 'aniversario', (string) $hoje->year);
                        $enviados++;
                    }
                });
        });

        return $enviados;
    }

    /**
     * Corre para TODAS as empresas. `referencia` = id do projeto, para
     * cada projeto só disparar este follow-up uma única vez, sempre.
     */
    public function enviarFollowUpsPosLancamento(TenantManager $tenantManager): int
    {
        $enviados = 0;
        $dataAlvo = now()->subDays(30)->toDateString();

        $tenantManager->semTenant(function () use ($dataAlvo, &$enviados): void {
            ProjetoMusical::query()
                ->where('estado', 'concluido')
                ->whereDate('concluido_em', $dataAlvo)
                ->with('cliente')
                ->chunkById(100, function ($projetos) use (&$enviados): void {
                    foreach ($projetos as $projeto) {
                        if ($projeto->cliente === null || $projeto->cliente->trashed()) {
                            continue;
                        }

                        if ($this->jaEnviadaEsteAno($projeto->cliente, 'follow_up_lancamento', (string) $projeto->id)) {
                            continue;
                        }

                        $this->notificacao->enviar(
                            $projeto->cliente,
                            'Como foi o lançamento?',
                            "Como foi o lançamento de \"{$projeto->nome}\"? Que tal gravar o próximo?"
                        );

                        $this->registarEnvio($projeto->cliente, 'follow_up_lancamento', (string) $projeto->id);
                        $enviados++;
                    }
                });
        });

        return $enviados;
    }

    /**
     * Envio manual, disparado por um utilizador autenticado — corre
     * dentro do tenant já identificado pelo middleware, sem bypass.
     */
    public function enviarCampanhaGenerica(string $identificadorCampanha, string $assunto, string $mensagem): int
    {
        $enviados = 0;

        Cliente::query()->chunkById(100, function ($clientes) use ($identificadorCampanha, $assunto, $mensagem, &$enviados): void {
            foreach ($clientes as $cliente) {
                if ($this->jaEnviadaEsteAno($cliente, 'data_comemorativa', $identificadorCampanha)) {
                    continue;
                }

                $this->notificacao->enviar($cliente, $assunto, $mensagem);
                $this->registarEnvio($cliente, 'data_comemorativa', $identificadorCampanha);
                $enviados++;
            }
        });

        return $enviados;
    }

    protected function gerarCupomAniversario(Cliente $cliente): CupomDesconto
    {
        return CupomDesconto::create([
            'empresa_id' => $cliente->empresa_id,
            'cliente_id' => $cliente->id,
            'codigo' => 'ANIV-'.strtoupper(Str::random(6)),
            'percentual_desconto' => config('estudiomusica.percentual_desconto_aniversario'),
            'motivo' => 'aniversario',
            'valido_ate' => now()->addDays((int) config('estudiomusica.validade_cupom_aniversario_dias')),
        ]);
    }

    protected function jaEnviadaEsteAno(Cliente $cliente, string $tipo, string $referencia): bool
    {
        return CampanhaEnviada::query()
            ->where('empresa_id', $cliente->empresa_id)
            ->where('cliente_id', $cliente->id)
            ->where('tipo', $tipo)
            ->where('referencia', $referencia)
            ->exists();
    }

    protected function registarEnvio(Cliente $cliente, string $tipo, string $referencia): void
    {
        CampanhaEnviada::create([
            'empresa_id' => $cliente->empresa_id,
            'cliente_id' => $cliente->id,
            'tipo' => $tipo,
            'referencia' => $referencia,
            'enviada_em' => now(),
        ]);
    }
}
