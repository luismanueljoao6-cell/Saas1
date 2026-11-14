<?php

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Services\ArmazemService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Tests\TestCase;

class ArmazemServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarEmpresa(string $nif): Empresa
    {
        return Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
    }

    public function test_cria_armazem_isolado_por_empresa(): void
    {
        $empresa = $this->criarEmpresa('630100001');

        $armazem = app(ArmazemService::class)->criar(
            $empresa,
            'loja-01',
            'Loja principal',
            'Armazenamento de venda',
            'Luanda',
            true
        );

        $this->assertSame('LOJA-01', $armazem->codigo);
        $this->assertTrue($armazem->principal);
        $this->assertDatabaseHas('armazens', [
            'id' => $armazem->id,
            'empresa_id' => $empresa->id,
            'codigo' => 'LOJA-01',
            'principal' => true,
            'ativo' => true,
        ]);
    }

    public function test_primeiro_armazem_criado_torna_se_principal_automaticamente(): void
    {
        $empresa = $this->criarEmpresa('630100006');

        $armazem = app(ArmazemService::class)->criar($empresa, 'A1', 'Armazém inicial');

        $this->assertTrue($armazem->principal);
        $this->assertSame(1, Armazem::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('principal', true)
            ->count());
    }

    public function test_obter_principal_reutiliza_armazem_ativo_existente_sem_principal(): void
    {
        $empresa = $this->criarEmpresa('630100007');
        $existente = Armazem::withoutGlobalScopes()->create([
            'empresa_id' => $empresa->id,
            'codigo' => 'LEGADO',
            'nome' => 'Local legado',
            'principal' => false,
            'ativo' => true,
        ]);

        $principal = app(ArmazemService::class)->obterPrincipal($empresa);

        $this->assertSame($existente->id, $principal->id);
        $this->assertTrue($principal->principal);
        $this->assertDatabaseCount('armazens', 1);
    }

    public function test_so_existe_um_armazem_principal_apos_nova_definicao(): void
    {
        $empresa = $this->criarEmpresa('630100002');
        $servico = app(ArmazemService::class);

        $primeiro = $servico->criar($empresa, 'A1', 'Armazém A', principal: true);
        $segundo = $servico->criar($empresa, 'B1', 'Armazém B', principal: true);

        $this->assertDatabaseHas('armazens', [
            'id' => $primeiro->id,
            'principal' => false,
        ]);
        $this->assertDatabaseHas('armazens', [
            'id' => $segundo->id,
            'principal' => true,
        ]);
        $this->assertSame(1, Armazem::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('principal', true)
            ->count());
    }

    public function test_cria_armazem_principal_padrao_de_forma_idempotente(): void
    {
        $empresa = $this->criarEmpresa('630100003');
        $servico = app(ArmazemService::class);

        $primeiro = $servico->obterPrincipal($empresa);
        $segundo = $servico->obterPrincipal($empresa);

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, Armazem::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->count());
    }

    public function test_recusa_operacao_quando_contexto_tenant_e_de_outra_empresa(): void
    {
        $empresaA = $this->criarEmpresa('630100004');
        $empresaB = $this->criarEmpresa('630100005');

        app(TenantManager::class)->set((int) $empresaA->id);

        $this->expectException(DomainException::class);

        app(ArmazemService::class)->criar($empresaB, 'B1', 'Armazém indevido');
    }
}
