<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PoliticaPasswordsTest extends TestCase
{
    protected function valida(string $password): bool
    {
        return Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes();
    }

    public function test_fora_de_producao_basta_o_minimo_de_8_caracteres(): void
    {
        $this->assertTrue($this->valida('abcdefgh'));
        $this->assertFalse($this->valida('abcdefg'));
    }

    public function test_em_producao_exige_10_caracteres_com_maiusculas_minusculas_e_numeros(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse($this->valida('abcdefgh'));
        $this->assertFalse($this->valida('abcdefghij'));
        $this->assertFalse($this->valida('ABCDEFGHIJ1'));
        $this->assertFalse($this->valida('Abcdefghij'));
        $this->assertTrue($this->valida('Abcdefghi10'));
    }
}
