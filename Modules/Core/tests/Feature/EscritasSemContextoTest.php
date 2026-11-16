<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Core\Traits\BelongsToTenant;
use Tests\TestCase;

/**
 * Escritas em models com BelongsToTenant sem tenant e sem bypass explícito.
 * Modos: permitir | avisar | bloquear (config core.tenant.escritas_sem_contexto).
 */
class EscritasSemContextoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('registos_teste_escrita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('registos_teste_escrita');

        parent::tearDown();
    }

    private function empresa(string $nif = '100000601'): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa Escritas', 'nif' => $nif]);
    }

    public function test_o_modo_configurado_e_um_valor_conhecido(): void
    {
        $this->assertContains(
            config('core.tenant.escritas_sem_contexto'),
            ['permitir', 'avisar', 'bloquear'],
        );
    }

    public function test_bloquear_recusa_criar_sem_contexto(): void
    {
        $empresa = $this->empresa();
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $this->expectException(LogicException::class);
        RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'sem contexto']);
    }

    public function test_bloquear_recusa_atualizar_sem_contexto(): void
    {
        $empresa = $this->empresa();
        $tenant = app(TenantManager::class);

        $tenant->set($empresa->id);
        $registo = RegistoTesteEscrita::create(['nome' => 'original']);
        $tenant->clear();

        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $this->expectException(LogicException::class);
        $registo->update(['nome' => 'alterado']);
    }

    public function test_bloquear_recusa_eliminar_sem_contexto(): void
    {
        $empresa = $this->empresa();
        $tenant = app(TenantManager::class);

        $tenant->set($empresa->id);
        $registo = RegistoTesteEscrita::create(['nome' => 'original']);
        $tenant->clear();

        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $this->expectException(LogicException::class);
        $registo->delete();
    }

    public function test_bloquear_deixa_escrever_com_tenant_ou_em_bypass_explicito(): void
    {
        $empresa = $this->empresa();
        $tenant = app(TenantManager::class);
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $tenant->set($empresa->id);
        $registo = RegistoTesteEscrita::create(['nome' => 'com tenant']);
        $registo->update(['nome' => 'com tenant, atualizado']);
        $tenant->clear();

        $tenant->semTenant(function () use ($registo, $empresa): void {
            $registo->update(['nome' => 'em bypass']);
            RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'criado em bypass']);
            $registo->delete();
        });

        $this->assertDatabaseHas('registos_teste_escrita', ['nome' => 'criado em bypass']);
        $this->assertDatabaseMissing('registos_teste_escrita', ['id' => $registo->id]);
    }

    public function test_avisar_regista_um_aviso_mas_deixa_escrever(): void
    {
        $empresa = $this->empresa();
        config(['core.tenant.escritas_sem_contexto' => 'avisar']);
        Log::spy();

        RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'sem contexto']);

        $this->assertDatabaseHas('registos_teste_escrita', ['nome' => 'sem contexto']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($mensagem, $contexto = []) => ($contexto['operacao'] ?? null) === 'criar'
                && ($contexto['model'] ?? null) === RegistoTesteEscrita::class
        );
    }

    public function test_permitir_mantem_o_comportamento_antigo(): void
    {
        $empresa = $this->empresa();
        config(['core.tenant.escritas_sem_contexto' => 'permitir']);

        RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'sem contexto']);

        $this->assertDatabaseHas('registos_teste_escrita', ['nome' => 'sem contexto']);
    }

    /**
     * O registo público corre sem tenant: em modo 'bloquear' continua a ter de
     * funcionar (empresa, utilizador, papéis, serviços, provisionamento) e o
     * e-mail de boas-vindas não pode falhar em silêncio por causa do bloqueio.
     */
    public function test_o_registo_de_empresa_funciona_em_modo_bloquear(): void
    {
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);
        Log::spy();

        $utilizador = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Empresa Registo Estrito', 'nif' => '100000777'],
            ['name' => 'Admin Registo', 'email' => 'admin-registo-estrito@example.test', 'password' => 'senha-segura-123'],
        );

        $this->assertDatabaseHas('empresas', ['nif' => '100000777']);
        $this->assertNotNull($utilizador->empresa_id);

        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn ($mensagem) => str_contains((string) $mensagem, 'e-mail de boas-vindas')),
            \Mockery::any(),
        ]);
    }
}

/**
 * Model de apoio só para este teste (mesmo padrão do IsolamentoTenantTest).
 */
class RegistoTesteEscrita extends Model
{
    use BelongsToTenant;

    protected $table = 'registos_teste_escrita';

    protected $fillable = ['nome', 'empresa_id'];
}
