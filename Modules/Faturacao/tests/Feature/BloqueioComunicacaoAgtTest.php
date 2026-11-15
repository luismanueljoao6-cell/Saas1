<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Support\GuardaEmissao;
use Tests\TestCase;

/**
 * Art. 17.º n.º 5 do Regime Jurídico das Facturas (DP 71/25): falha de comunicação superior
 * a 60 dias bloqueia a emissão de novos documentos.
 */
class BloqueioComunicacaoAgtTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['faturacao.agt.ativo' => true, 'faturacao.agt.bloqueio_dias_sem_comunicacao' => 60]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Comunicação', 'nif' => '5001636863']);
    }

    protected function submissao(string $estado, int $diasAtras, ?Empresa $empresa = null, int $id = 1): AgtSubmissao
    {
        $s = AgtSubmissao::create([
            'empresa_id' => ($empresa ?? $this->empresa)->id,
            'documento_type' => 'fatura',
            'documento_id' => $id,
            'documento_no' => "FT A2026/{$id}",
            'estado' => $estado,
        ]);

        $s->forceFill(['created_at' => now()->subDays($diasAtras)])->saveQuietly();

        return $s;
    }

    public function test_pendente_ha_mais_de_60_dias_bloqueia_a_emissao(): void
    {
        $this->submissao(AgtSubmissao::PENDENTE, 61);

        $this->expectException(EmissaoNaoPermitidaException::class);

        GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);
    }

    public function test_erro_tecnico_e_aguarda_intervencao_tambem_contam(): void
    {
        foreach ([AgtSubmissao::ERRO, AgtSubmissao::AGUARDA_INTERVENCAO] as $i => $estado) {
            AgtSubmissao::query()->delete();
            $this->submissao($estado, 90, null, $i + 1);

            try {
                GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);
                $this->fail("O estado {$estado} devia bloquear.");
            } catch (EmissaoNaoPermitidaException $e) {
                $this->assertStringContainsString('60 dias', $e->getMessage());
            }
        }
    }

    public function test_pendente_recente_nao_bloqueia(): void
    {
        $this->submissao(AgtSubmissao::PENDENTE, 59);

        GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);

        $this->assertTrue(true);
    }

    public function test_respostas_da_agt_nao_sao_falha_de_comunicacao(): void
    {
        $this->submissao(AgtSubmissao::VALIDO, 200, null, 1);
        $this->submissao(AgtSubmissao::INVALIDO, 200, null, 2);
        $this->submissao(AgtSubmissao::REJEITADO, 200, null, 3);

        GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);

        $this->assertTrue(true);
    }

    public function test_so_conta_a_propria_empresa(): void
    {
        $outra = Empresa::create(['nome_comercial' => 'Outra', 'nif' => '5001636864']);
        $this->submissao(AgtSubmissao::PENDENTE, 120, $outra);

        GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);

        $this->assertTrue(true);
    }

    public function test_sem_agt_ativa_ou_com_limite_zero_nao_bloqueia(): void
    {
        $this->submissao(AgtSubmissao::PENDENTE, 120);

        config(['faturacao.agt.ativo' => false]);
        GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);

        config(['faturacao.agt.ativo' => true, 'faturacao.agt.bloqueio_dias_sem_comunicacao' => 0]);
        GuardaEmissao::garantirComunicacaoAgtEmDia((int) $this->empresa->id);

        $this->assertTrue(true);
    }

    public function test_garantir_aplica_o_bloqueio_a_todos_os_documentos(): void
    {
        $this->submissao(AgtSubmissao::PENDENTE, 61);

        $this->expectException(EmissaoNaoPermitidaException::class);

        GuardaEmissao::garantir((int) $this->empresa->id);
    }
}
