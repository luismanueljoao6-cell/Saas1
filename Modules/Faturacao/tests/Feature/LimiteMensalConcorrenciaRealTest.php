<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Faturacao\Services\FaturaService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PreparaEmpresaConcorrente;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * O limite mensal de faturas do plano ('max_faturas_mes') sob concorrência real (MySQL): várias
 * emissões simultâneas não podem consumir a mesma última vaga. A emissão bloqueia a linha da
 * empresa e depois conta as faturas emitidas no mês, uma contagem que só é correcta se ler o que
 * os outros processos já confirmaram (READ COMMITTED, ver ConcorrenciaFinanceiraRealTest).
 */
#[Group('concorrencia-real')]
class LimiteMensalConcorrenciaRealTest extends TestCase
{
    use PreparaEmpresaConcorrente;
    use ProcessosConcorrentes;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->prepararEmpresaFiscal('limite-mensal');
    }

    public function test_seis_emissoes_simultaneas_com_limite_de_duas_emitem_apenas_duas(): void
    {
        $servico = app(FaturaService::class);

        $argumentos = [];
        for ($i = 1; $i <= 6; $i++) {
            $rascunho = $servico->criarRascunho($this->empresa, $this->cliente, [
                ['descricao' => "Serviço {$i}", 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
            ]);
            $argumentos[] = ['fatura_id' => $rascunho->id, 'limite_faturas' => 2];
        }

        $resultados = $this->correrEmParalelo('emitir', $argumentos, $this->ambienteFiscal());

        $this->assertSame(2, $this->aceites($resultados), 'O plano permite 2 faturas por mês.');
        $this->assertRecusasDeNegocio($resultados, 'EmissaoNaoPermitidaException', 'no máximo 2 fatura(s)');
        $this->assertSame(2, DB::table('faturas')->where('empresa_id', $this->empresa->id)->where('estado', 'emitida')->count());
    }
}
