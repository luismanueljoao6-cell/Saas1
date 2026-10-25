<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Services\NumeracaoService;
use Tests\TestCase;

/** Séries autorizadas pela AGT são por estabelecimento: nunca se usa a de outro. */
class SerieEstabelecimentoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['faturacao.agt.ativo' => true, 'faturacao.agt.estabelecimento_padrao' => 'SEDE']);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Dois Estabelecimentos', 'nif' => '5001636863']);
    }

    protected function serie(string $codigo, string $estabelecimento): Serie
    {
        return Serie::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento' => 'FT',
            'ano' => now()->year,
            'prefixo' => $codigo, // tal como AgtSerieService::guardar()
            'ultimo_numero' => 0,
            'agt_codigo' => $codigo,
            'estabelecimento' => $estabelecimento,
            'contingencia' => 'N',
            'quantidade_autorizada' => 1000,
            'ultimo_numero_autorizado' => 1000,
        ]);
    }

    public function test_cada_estabelecimento_usa_a_sua_propria_serie(): void
    {
        $this->serie('FT1SEDE', 'SEDE');
        $this->serie('FT1LOJA2', 'LOJA2');
        $numeracao = app(NumeracaoService::class);

        $padrao = $numeracao->proximoNumero($this->empresa->id, 'FT');
        $loja2 = $numeracao->proximoNumero($this->empresa->id, 'FT', 'A', 'LOJA2');
        $loja2Outra = $numeracao->proximoNumero($this->empresa->id, 'FT', 'A', 'LOJA2');

        $this->assertSame('FT FT1SEDE/1', $padrao['numero_documento']);
        $this->assertSame('FT FT1LOJA2/1', $loja2['numero_documento']);
        $this->assertSame('FT FT1LOJA2/2', $loja2Outra['numero_documento']);
    }

    public function test_estabelecimento_sem_serie_autorizada_nao_herda_a_de_outro(): void
    {
        $this->serie('FT1SEDE', 'SEDE');

        $this->expectException(AgtException::class);

        app(NumeracaoService::class)->proximoNumero($this->empresa->id, 'FT', 'A', 'LOJA3');
    }
}
