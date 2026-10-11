<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Tests\TestCase;

class SuperAdminPainelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_super_admin_sem_empresa_abre_o_painel_sem_erro(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('core.painel'))
            ->assertOk()
            ->assertSee('Conta de administração da plataforma');
    }
}
