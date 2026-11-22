<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Confirmação de pagamentos de subscrições sob concorrência real (MySQL): um webhook duplicado não
 * soma dias duas vezes e dois pagamentos da mesma empresa confirmados em simultâneo somam ambos
 * os períodos (nenhum sobrescreve o outro). O serviço bloqueia o pagamento e a empresa e decide
 * com as linhas lidas sob FOR UPDATE.
 */
#[Group('concorrencia-real')]
class PagamentoConcorrenciaRealTest extends TestCase
{
    use ProcessosConcorrentes;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();
    }

    /** @return array{0: Empresa, 1: Subscricao} */
    protected function empresaComSubscricaoPendente(): array
    {
        return app(TenantManager::class)->semTenant(function () {
            $nif = (string) random_int(900000000, 999999999);
            $empresa = Empresa::create(['nome_comercial' => 'Subscrição Concorrente '.$nif, 'nif' => $nif]);
            $plano = Plano::create(['nome' => 'Plano '.$nif, 'slug' => 'plano-'.$nif, 'preco' => 9900, 'periodo_dias' => 30]);

            $subscricao = Subscricao::create([
                'empresa_id' => $empresa->id,
                'plano_id' => $plano->id,
                'periodo_dias' => 30,
                'preco' => 9900,
                'moeda' => 'AOA',
                'limites' => [],
                'servicos' => [],
                'estado' => 'pendente',
                'renovacao_automatica' => true,
            ]);

            return [$empresa, $subscricao];
        });
    }

    protected function pagamentoPendente(Subscricao $subscricao): Pagamento
    {
        return app(TenantManager::class)->semTenant(fn () => Pagamento::create([
            'empresa_id' => $subscricao->empresa_id,
            'subscricao_id' => $subscricao->id,
            'gateway' => 'proxypay',
            'referencia_externa' => 'REF'.random_int(100000000, 999999999),
            'valor' => '9900.00',
            'moeda' => 'AOA',
            'estado' => 'pendente',
            'payload_bruto' => ['entity_id' => '12345'],
            'expira_em' => now()->addDays(3),
        ]));
    }

    /** Dias entre agora e a data de fim do período da empresa (arredondados: o teste leva segundos). */
    protected function diasAteExpirar(Empresa $empresa): int
    {
        $expira = DB::table('empresas')->where('id', $empresa->id)->value('subscricao_expira_em');

        return (int) round(now()->floatDiffInDays($expira, false));
    }

    public function test_seis_webhooks_duplicados_do_mesmo_pagamento_somam_o_periodo_uma_so_vez(): void
    {
        [$empresa, $subscricao] = $this->empresaComSubscricaoPendente();
        $pagamento = $this->pagamentoPendente($subscricao);

        $argumentos = array_fill(0, 6, ['pagamento_id' => $pagamento->id]);
        $resultados = $this->correrEmParalelo('confirmar_pagamento', $argumentos);

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Um webhook duplicado falhou: '.($r['erro'] ?? '?'));
            $this->assertSame('confirmado', $r['estado']);
        }

        $this->assertSame(30, $this->diasAteExpirar($empresa), 'O período de 30 dias soma-se uma só vez.');
        $this->assertSame(1, DB::table('pagamentos')->where('subscricao_id', $subscricao->id)->where('estado', 'confirmado')->count());
    }

    public function test_dois_pagamentos_simultaneos_da_mesma_empresa_somam_os_dois_periodos(): void
    {
        [$empresa, $subscricao] = $this->empresaComSubscricaoPendente();
        $primeiro = $this->pagamentoPendente($subscricao);
        $segundo = $this->pagamentoPendente($subscricao);

        $resultados = $this->correrEmParalelo('confirmar_pagamento', [
            ['pagamento_id' => $primeiro->id],
            ['pagamento_id' => $segundo->id],
        ]);

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Uma confirmação falhou: '.($r['erro'] ?? '?'));
        }

        $this->assertSame(60, $this->diasAteExpirar($empresa), 'Os dois períodos pagos (2 x 30 dias) têm de somar-se.');
        $this->assertSame(2, DB::table('pagamentos')->where('subscricao_id', $subscricao->id)->where('estado', 'confirmado')->count());
    }
}
