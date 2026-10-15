<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Modules\EstudioMusica\Support\NumeroTelefone;
use Tests\TestCase;

class NumeroTelefoneTest extends TestCase
{
    public function test_numero_angolano_sem_indicativo_recebe_244(): void
    {
        $this->assertSame('244923456789', NumeroTelefone::internacional('923 456 789'));
        $this->assertSame('244923456789', NumeroTelefone::internacional('0923456789'));
    }

    public function test_numero_com_indicativo_mantem_se(): void
    {
        $this->assertSame('244923456789', NumeroTelefone::internacional('+244 923 456 789'));
        $this->assertSame('244923456789', NumeroTelefone::internacional('00244923456789'));
        $this->assertSame('351912345678', NumeroTelefone::internacional('+351 912 345 678'));
    }

    public function test_numero_invalido_devolve_null(): void
    {
        $this->assertNull(NumeroTelefone::internacional(null));
        $this->assertNull(NumeroTelefone::internacional('12345'));
    }

    public function test_mascarar_nao_revela_o_numero_completo(): void
    {
        $this->assertSame('*********789', NumeroTelefone::mascarar('244923456789'));
    }
}
