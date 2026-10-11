<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Http\Requests\AtualizarEmpresaRequest;
use Tests\TestCase;

class NifEdicaoEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected function nifFalha(string $nif): bool
    {
        $regras = (new AtualizarEmpresaRequest)->rules();

        return Validator::make(['nif' => $nif], ['nif' => $regras['nif']])->fails();
    }

    public function test_nif_com_espacos_ou_simbolos_e_rejeitado(): void
    {
        $this->assertTrue($this->nifFalha('500 000 123'));
        $this->assertTrue($this->nifFalha('5000001-23!'));
    }

    public function test_nif_alfanumerico_valido_passa(): void
    {
        $this->assertFalse($this->nifFalha('5000001234'));
        $this->assertFalse($this->nifFalha('000123456LA041'));
    }
}
