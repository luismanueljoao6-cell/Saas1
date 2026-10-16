<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Carbon\Carbon;
use Modules\EstudioMusica\Support\Aniversario;
use Tests\TestCase;

class AniversarioTest extends TestCase
{
    public function test_aniversario_normal(): void
    {
        $this->assertTrue(Aniversario::hoje('1995-07-14', Carbon::parse('2026-07-14')));
        $this->assertFalse(Aniversario::hoje('1995-07-14', Carbon::parse('2026-07-15')));
    }

    public function test_nascido_a_29_de_fevereiro_festeja_a_28_nos_anos_nao_bissextos(): void
    {
        $this->assertTrue(Aniversario::hoje('2000-02-29', Carbon::parse('2026-02-28')));
        $this->assertFalse(Aniversario::hoje('2000-02-29', Carbon::parse('2026-03-01')));
    }

    public function test_nascido_a_29_de_fevereiro_festeja_a_29_nos_anos_bissextos(): void
    {
        $this->assertTrue(Aniversario::hoje('2000-02-29', Carbon::parse('2028-02-29')));
        $this->assertFalse(Aniversario::hoje('2000-02-29', Carbon::parse('2028-02-28')));
    }
}
