<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Services\DoisFatoresService;
use PHPUnit\Framework\TestCase;

class DoisFatoresCodigoTest extends TestCase
{
    /** Apêndice B da RFC 6238 (SHA-1, segredo ASCII "12345678901234567890"), 6 dígitos. */
    public function test_vetores_oficiais_da_rfc_6238(): void
    {
        $segredo = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $servico = new DoisFatoresService;

        $esperados = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($esperados as $instante => $codigo) {
            $this->assertSame($codigo, $servico->codigo($segredo, $servico->passoDe($instante)), "T={$instante}");
        }
    }

    public function test_segredo_gerado_e_base32_valido_e_produz_codigos(): void
    {
        $servico = new DoisFatoresService;
        $segredo = $servico->gerarSegredo();

        $this->assertSame(32, strlen($segredo));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $segredo);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $servico->codigo($segredo, 1));
    }

    public function test_passo_valido_respeita_a_janela_e_o_ultimo_passo(): void
    {
        $servico = new DoisFatoresService;
        $segredo = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $agora = 1111111109; // passo 37037036

        $passo = $servico->passoDe($agora);

        $this->assertSame($passo, $servico->passoValido($segredo, $servico->codigo($segredo, $passo), null, $agora));
        $this->assertSame($passo - 1, $servico->passoValido($segredo, $servico->codigo($segredo, $passo - 1), null, $agora));
        $this->assertSame($passo + 1, $servico->passoValido($segredo, $servico->codigo($segredo, $passo + 1), null, $agora));
        $this->assertNull($servico->passoValido($segredo, $servico->codigo($segredo, $passo - 2), null, $agora));
        $this->assertNull($servico->passoValido($segredo, $servico->codigo($segredo, $passo + 2), null, $agora));
        $this->assertNull($servico->passoValido($segredo, $servico->codigo($segredo, $passo), $passo, $agora));
        $this->assertNull($servico->passoValido($segredo, 'abc123', null, $agora));
    }
}
