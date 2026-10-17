<?php

namespace Modules\Subscricoes\Tests\Unit;

use Modules\Subscricoes\Services\ConfiguracaoPagamentoValidator;
use PHPUnit\Framework\TestCase;

class ConfiguracaoPagamentoValidatorTest extends TestCase
{
    private function valido(): array
    {
        return [
            'api_key' => 'chave',
            'entity_id' => '12345',
            'base_url' => 'https://api.proxypay.co.ao',
        ];
    }

    public function test_configuracao_valida_em_producao(): void
    {
        $r = (new ConfiguracaoPagamentoValidator)->validar($this->valido(), true);

        $this->assertSame([], $r['erros']);
    }

    public function test_entidade_em_falta_e_erro(): void
    {
        $cfg = $this->valido();
        $cfg['entity_id'] = '';

        $r = (new ConfiguracaoPagamentoValidator)->validar($cfg, true);

        $this->assertNotEmpty($r['erros']);
    }

    public function test_entidade_nao_numerica_e_erro(): void
    {
        $cfg = $this->valido();
        $cfg['entity_id'] = '12A45';

        $r = (new ConfiguracaoPagamentoValidator)->validar($cfg, true);

        $this->assertNotEmpty($r['erros']);
    }

    public function test_api_key_em_falta_e_erro(): void
    {
        $cfg = $this->valido();
        $cfg['api_key'] = null;

        $r = (new ConfiguracaoPagamentoValidator)->validar($cfg, false);

        $this->assertNotEmpty($r['erros']);
    }

    public function test_url_sem_https_e_erro(): void
    {
        $cfg = $this->valido();
        $cfg['base_url'] = 'http://api.proxypay.co.ao';

        $r = (new ConfiguracaoPagamentoValidator)->validar($cfg, false);

        $this->assertNotEmpty($r['erros']);
    }

    public function test_sandbox_em_producao_e_erro(): void
    {
        $cfg = $this->valido();
        $cfg['base_url'] = 'https://api.sandbox.proxypay.co.ao';

        $r = (new ConfiguracaoPagamentoValidator)->validar($cfg, true);

        $this->assertNotEmpty($r['erros']);
    }

    public function test_sandbox_fora_de_producao_e_valida_e_sem_avisos(): void
    {
        $cfg = $this->valido();
        $cfg['base_url'] = 'https://api.sandbox.proxypay.co.ao';

        $r = (new ConfiguracaoPagamentoValidator)->validar($cfg, false);

        $this->assertSame([], $r['erros']);
        $this->assertSame([], $r['avisos']);
    }

    public function test_api_real_fora_de_producao_e_apenas_aviso(): void
    {
        $r = (new ConfiguracaoPagamentoValidator)->validar($this->valido(), false);

        $this->assertSame([], $r['erros']);
        $this->assertNotEmpty($r['avisos']);
    }
}
