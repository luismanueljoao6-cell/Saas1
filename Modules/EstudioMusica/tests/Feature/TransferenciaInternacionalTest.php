<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Notifications\Channels\LogChannel;
use Modules\EstudioMusica\Support\TransferenciaInternacional;
use Tests\TestCase;

/**
 * Lei n.º 22/11: canais que levam contactos para fora de Angola só enviam
 * com autorização da APD (ou se o responsável desligar a exigência).
 */
class TransferenciaInternacionalTest extends TestCase
{
    protected function canalExterno(): CanalWhatsAppInterface
    {
        return new class implements CanalWhatsAppInterface
        {
            public function identificador(): string
            {
                return 'externo-teste';
            }

            public function enviar(string $destino, string $mensagem): bool
            {
                return true;
            }
        };
    }

    public function test_canal_externo_fica_bloqueado_sem_autorizacao_da_apd(): void
    {
        config(['estudiomusica.exigir_autorizacao_apd' => true, 'estudiomusica.apd_autorizacao_transferencia' => null]);

        $this->assertFalse(TransferenciaInternacional::permitida($this->canalExterno()));
    }

    public function test_canal_externo_e_permitido_com_o_numero_da_autorizacao(): void
    {
        config(['estudiomusica.exigir_autorizacao_apd' => true, 'estudiomusica.apd_autorizacao_transferencia' => 'APD-123/2026']);

        $this->assertTrue(TransferenciaInternacional::permitida($this->canalExterno()));
    }

    public function test_exigencia_pode_ser_desligada_pelo_responsavel(): void
    {
        config(['estudiomusica.exigir_autorizacao_apd' => false, 'estudiomusica.apd_autorizacao_transferencia' => null]);

        $this->assertTrue(TransferenciaInternacional::permitida($this->canalExterno()));
    }

    public function test_canal_log_nunca_e_bloqueado_porque_nao_sai_do_servidor(): void
    {
        config(['estudiomusica.exigir_autorizacao_apd' => true, 'estudiomusica.apd_autorizacao_transferencia' => null]);

        $this->assertTrue(TransferenciaInternacional::permitida(new LogChannel));
    }
}
