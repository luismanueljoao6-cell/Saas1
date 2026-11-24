<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\ProjetoMusical;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PreparaEmpresaConcorrente;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Faturação de projetos do Estúdio sob concorrência real (MySQL): o sinal e o saldo final de um
 * projeto por pacote só podem ser faturados uma vez, por muitos cliques ou pedidos simultâneos que
 * haja. O saldo ("pacote menos o já faturado") é calculado depois de bloquear o projeto e só vê a
 * fatura do outro processo se ler o que já está confirmado (READ COMMITTED).
 */
#[Group('concorrencia-real')]
class FaturacaoEstudioConcorrenciaRealTest extends TestCase
{
    use PreparaEmpresaConcorrente;
    use ProcessosConcorrentes;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->prepararEmpresaFiscal('estudio-conc');
        config(['faturacao.taxa_iva_geral' => 14.0]);
        app(TenantManager::class)->set($this->empresa->id);
    }

    protected function projetoPorPacote(): ProjetoMusical
    {
        return ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Álbum Concorrente '.uniqid(),
            'tipo_cobranca' => 'pacote',
            'valor_pacote' => 100000,
            'percentual_sinal' => 40,
        ]);
    }

    protected function ambiente(): array
    {
        return $this->ambienteFiscal() + ['FATURACAO_TAXA_IVA_GERAL' => '14'];
    }

    protected function totalFaturado(): string
    {
        return number_format((float) DB::table('faturas')->where('empresa_id', $this->empresa->id)->where('estado', 'emitida')->sum('valor_total'), 2, '.', '');
    }

    public function test_seis_pedidos_simultaneos_de_saldo_final_geram_uma_so_fatura(): void
    {
        $projeto = $this->projetoPorPacote();

        $argumentos = array_fill(0, 6, ['empresa_id' => $this->empresa->id, 'projeto_id' => $projeto->id]);
        $resultados = $this->correrEmParalelo('estudio_saldo_final', $argumentos, $this->ambiente());

        $this->assertSame(1, $this->aceites($resultados), 'O saldo final só se fatura uma vez.');
        $this->assertRecusasDeNegocio($resultados, 'InvalidArgumentException', 'já não tem saldo por faturar');
        $this->assertSame('114000.00', $this->totalFaturado(), 'Pacote de 100000.00 + 14% de IVA, uma só vez.');
    }

    public function test_seis_pedidos_simultaneos_de_sinal_geram_uma_so_fatura(): void
    {
        $projeto = $this->projetoPorPacote();

        $argumentos = array_fill(0, 6, ['empresa_id' => $this->empresa->id, 'projeto_id' => $projeto->id]);
        $resultados = $this->correrEmParalelo('estudio_sinal', $argumentos, $this->ambiente());

        $this->assertSame(1, $this->aceites($resultados), 'O sinal só se fatura uma vez.');
        $this->assertRecusasDeNegocio($resultados, 'InvalidArgumentException', 'sinal deste projeto já foi emitido');
        $this->assertSame('45600.00', $this->totalFaturado(), 'Sinal de 40% (40000.00) + 14% de IVA, uma só vez.');
    }
}
