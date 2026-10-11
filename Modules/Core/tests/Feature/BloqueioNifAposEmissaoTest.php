<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BloqueioNifAposEmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array{0: Empresa, 1: User} */
    protected function cenario(string $nif, bool $comFaturaEmitida): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nome_legal' => 'Legal '.$nif,
            'nif' => $nif,
        ]);

        $admin = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador',
            'email' => 'admin'.$nif.'@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $admin->assignRole(Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        if ($comFaturaEmitida) {
            $serieId = DB::table('series')->insertGetId([
                'empresa_id' => $empresa->id,
                'tipo_documento' => 'FT',
                'ano' => 2026,
                'prefixo' => 'A',
                'ultimo_numero' => 1,
            ]);
            $clienteId = DB::table('clientes')->insertGetId([
                'empresa_id' => $empresa->id,
                'nome' => 'Cliente',
            ]);
            DB::table('faturas')->insert([
                'empresa_id' => $empresa->id,
                'serie_id' => $serieId,
                'cliente_id' => $clienteId,
                'estado' => 'emitida',
            ]);
        }

        return [$empresa, $admin];
    }

    protected function atualizar(User $admin, Empresa $empresa, array $dados): TestResponse
    {
        return $this->actingAs($admin)->put(route('core.empresa.atualizar', $empresa->id), array_merge([
            'nome_comercial' => $empresa->nome_comercial,
            'nome_legal' => $empresa->nome_legal,
            'nif' => $empresa->nif,
        ], $dados));
    }

    public function test_nif_nao_pode_mudar_depois_de_emitir_documentos(): void
    {
        [$empresa, $admin] = $this->cenario('700000001', comFaturaEmitida: true);

        $this->atualizar($admin, $empresa, ['nif' => '700000099'])
            ->assertSessionHasErrors('nif');

        $this->assertSame('700000001', $empresa->fresh()->nif);
    }

    public function test_nome_legal_nao_pode_mudar_depois_de_emitir_documentos(): void
    {
        [$empresa, $admin] = $this->cenario('700000002', comFaturaEmitida: true);

        $this->atualizar($admin, $empresa, ['nome_legal' => 'Outro Nome Lda'])
            ->assertSessionHasErrors('nome_legal');

        $this->assertSame('Legal 700000002', $empresa->fresh()->nome_legal);
    }

    public function test_sem_documentos_emitidos_o_nif_pode_mudar(): void
    {
        [$empresa, $admin] = $this->cenario('700000003', comFaturaEmitida: false);

        $this->atualizar($admin, $empresa, ['nif' => '700000098'])
            ->assertSessionHasNoErrors();

        $this->assertSame('700000098', $empresa->fresh()->nif);
    }
}
