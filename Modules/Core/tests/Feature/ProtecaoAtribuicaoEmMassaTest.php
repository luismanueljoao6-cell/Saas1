<?php

namespace Modules\Core\Tests\Feature;

use Modules\Core\Models\User;
use Tests\TestCase;

class ProtecaoAtribuicaoEmMassaTest extends TestCase
{
    public function test_is_super_admin_nao_e_atribuivel_em_massa(): void
    {
        $utilizador = new User;
        $utilizador->fill(['name' => 'Ana', 'is_super_admin' => true]);

        $this->assertFalse($utilizador->is_super_admin);
    }

    public function test_factory_do_user_resolve_e_permite_super_admin(): void
    {
        $this->assertFalse(User::factory()->make()->is_super_admin);
        $this->assertTrue(User::factory()->superAdmin()->make()->is_super_admin);
    }
}
