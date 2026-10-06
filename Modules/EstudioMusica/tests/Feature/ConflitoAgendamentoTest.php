<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Exceptions\ConflitoAgendamentoException;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Services\AgendamentoService;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Cobre a regra mais arriscada do módulo (secção B): duas sessões nunca
 * podem ocupar a mesma sala em horários sobrepostos. Ao contrário de
 * Faturacao\NumeracaoService (que faz bypass explícito do tenant, por
 * operar propositadamente entre empresas), AgendamentoService faz queries
 * normais e à espera de um tenant já identificado — por isso este teste
 * define o tenant explicitamente, tal como o middleware 'tenant' faria
 * num pedido HTTP real.
 */
class ConflitoAgendamentoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected SalaEstudio $sala;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Teste', 'nif' => '400000001']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista Teste']);
        $this->sala = SalaEstudio::create(['empresa_id' => $this->empresa->id, 'nome' => 'Sala A', 'preco_hora' => 5000]);
    }

    protected function dadosSessao(string $inicio, string $fim): array
    {
        return [
            'sala_estudio_id' => $this->sala->id,
            'cliente_id' => $this->cliente->id,
            'tipo_servico' => 'mixagem',
            'inicio_previsto' => $inicio,
            'fim_previsto' => $fim,
        ];
    }

    public function test_duas_sessoes_sobrepostas_na_mesma_sala_geram_conflito(): void
    {
        $servico = app(AgendamentoService::class);
        $servico->criarSessao($this->dadosSessao('2026-05-01 10:00:00', '2026-05-01 12:00:00'));

        $this->expectException(ConflitoAgendamentoException::class);

        $servico->criarSessao($this->dadosSessao('2026-05-01 11:00:00', '2026-05-01 13:00:00'));
    }

    public function test_sessoes_consecutivas_sem_sobreposicao_nao_geram_conflito(): void
    {
        $servico = app(AgendamentoService::class);
        $servico->criarSessao($this->dadosSessao('2026-05-01 10:00:00', '2026-05-01 12:00:00'));

        $segunda = $servico->criarSessao($this->dadosSessao('2026-05-01 12:00:00', '2026-05-01 14:00:00'));

        $this->assertNotNull($segunda->id);
    }

    public function test_sessao_cancelada_liberta_a_sala_para_o_mesmo_horario(): void
    {
        $servico = app(AgendamentoService::class);
        $primeira = $servico->criarSessao($this->dadosSessao('2026-05-01 10:00:00', '2026-05-01 12:00:00'));
        $servico->cancelar($primeira);

        $segunda = $servico->criarSessao($this->dadosSessao('2026-05-01 10:00:00', '2026-05-01 12:00:00'));

        $this->assertNotNull($segunda->id);
    }
}
