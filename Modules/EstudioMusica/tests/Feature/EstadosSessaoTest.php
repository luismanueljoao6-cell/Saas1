<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Services\AgendamentoService;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Regras de estado das sessões: o que se pode (e não pode) fazer a uma
 * sessão consoante o seu estado. Todas estas guardas vivem no
 * AgendamentoService, por isso valem para qualquer chamador, não só para
 * o FormRequest.
 */
class EstadosSessaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected SalaEstudio $sala;

    protected AgendamentoService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Teste', 'nif' => '400000003']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista Teste']);
        $this->sala = SalaEstudio::create(['empresa_id' => $this->empresa->id, 'nome' => 'Sala A', 'preco_hora' => 5000]);
        $this->servico = app(AgendamentoService::class);
    }

    protected function sessao(string $inicio = '+2 hours', string $fim = '+4 hours'): SessaoEstudio
    {
        return $this->servico->criarSessao([
            'sala_estudio_id' => $this->sala->id,
            'cliente_id' => $this->cliente->id,
            'tipo_servico' => 'mixagem',
            'inicio_previsto' => Carbon::parse($inicio),
            'fim_previsto' => Carbon::parse($fim),
        ]);
    }

    public function test_nao_se_agenda_em_sala_inativa(): void
    {
        $this->sala->update(['ativa' => false]);

        $this->expectException(InvalidArgumentException::class);

        $this->sessao();
    }

    public function test_fim_anterior_ao_inicio_e_rejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sessao('+4 hours', '+2 hours');
    }

    public function test_sessao_cancelada_nao_pode_ser_reagendada(): void
    {
        $sessao = $this->sessao();
        $this->servico->cancelar($sessao);

        $this->expectException(InvalidArgumentException::class);

        $this->servico->reagendar($sessao, Carbon::parse('+10 hours'), Carbon::parse('+12 hours'));
    }

    public function test_sessao_em_curso_nao_pode_ser_cancelada(): void
    {
        $sessao = $this->servico->registrarCheckIn($this->sessao());

        $this->expectException(InvalidArgumentException::class);

        $this->servico->cancelar($sessao);
    }

    public function test_check_in_muito_antes_da_hora_marcada_e_rejeitado(): void
    {
        $sessao = $this->sessao('+3 days', '+3 days +2 hours');

        $this->expectException(InvalidArgumentException::class);

        $this->servico->registrarCheckIn($sessao);
    }

    public function test_check_in_dentro_da_janela_e_aceite(): void
    {
        $sessao = $this->servico->registrarCheckIn($this->sessao('+1 hour', '+3 hours'));

        $this->assertSame('em_curso', $sessao->estado);
        $this->assertNotNull($sessao->inicio_real);
    }
}
