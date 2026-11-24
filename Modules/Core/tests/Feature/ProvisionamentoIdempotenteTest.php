<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantProvisionerRegistry;
use Modules\Core\Support\Servico;
use Tests\TestCase;

/**
 * Evidência de idempotência com os provisionadores REAIS da aplicação (Armazém sob
 * 'faturacao', Estúdio sob 'estudio', ...): repetir não duplica nem altera nada, e uma
 * empresa nunca mexe nas outras. O registo falhado também não deixa nada para trás.
 */
class ProvisionamentoIdempotenteTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Senha-Forte-123!';

    private static int $nif = 630000000;

    protected function empresaComTodosOsServicos(): Empresa
    {
        $nif = (string) self::$nif++;

        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos(Servico::valores());

        return $empresa;
    }

    /**
     * Estado completo de papéis, permissões e ligações. Se um provisionador duplicar
     * ou alterar alguma coisa ao ser repetido, a fotografia muda.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function fotografia(): array
    {
        $linhas = fn ($consulta) => $consulta->get()->map(fn ($linha) => (array) $linha)->all();

        return [
            'roles' => $linhas(DB::table('roles')->orderBy('id')->select(['id', 'name', 'guard_name', 'empresa_id'])),
            'permissions' => $linhas(DB::table('permissions')->orderBy('id')->select(['id', 'name', 'guard_name'])),
            'ligacoes' => $linhas(DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->select(['role_id', 'permission_id'])),
        ];
    }

    public function test_correr_os_provisionadores_reais_duas_vezes_nao_duplica_nada(): void
    {
        $empresa = $this->empresaComTodosOsServicos();
        $registry = app(TenantProvisionerRegistry::class);

        $registry->provision($empresa->id, Servico::valores());
        $primeira = $this->fotografia();

        // O teste não é vazio: o provisionamento criou mesmo alguma coisa.
        $this->assertNotEmpty(array_merge($primeira['roles'], $primeira['permissions']));

        $registry->provision($empresa->id, Servico::valores());

        $this->assertSame($primeira, $this->fotografia());
    }

    public function test_reprovisionar_uma_empresa_nao_mexe_nas_outras(): void
    {
        $a = $this->empresaComTodosOsServicos();
        $b = $this->empresaComTodosOsServicos();
        $registry = app(TenantProvisionerRegistry::class);

        $registry->provision($a->id, Servico::valores());
        $registry->provision($b->id, Servico::valores());

        $papeisDeB = fn (): array => DB::table('roles')
            ->where('empresa_id', $b->id)
            ->orderBy('id')
            ->select(['id', 'name'])
            ->get()
            ->map(fn ($linha) => (array) $linha)
            ->all();

        $antes = $papeisDeB();

        $registry->provision($a->id, Servico::valores());

        $this->assertSame($antes, $papeisDeB());
    }

    public function test_registo_repetido_com_o_mesmo_nif_falha_sem_deixar_nada_para_tras(): void
    {
        $servico = app(RegistoEmpresaService::class);
        $empresa = ['nome_comercial' => 'Empresa Repetida', 'nif' => '639999991'];

        $primeiro = $servico->registar(
            $empresa,
            ['name' => 'Primeiro', 'email' => 'primeiro@teste.test', 'password' => self::SENHA],
            ['estudio'],
        );
        $this->assertNotNull($primeiro->empresa_id);

        $contagens = fn (): array => [
            'empresas' => DB::table('empresas')->count(),
            'users' => DB::table('users')->count(),
            'servicos' => DB::table('empresa_servicos')->count(),
            'roles' => DB::table('roles')->count(),
        ];
        $antes = $contagens();

        $falhou = false;

        try {
            $servico->registar(
                $empresa,
                ['name' => 'Segundo', 'email' => 'segundo@teste.test', 'password' => self::SENHA],
                ['estudio'],
            );
        } catch (QueryException) {
            // Esperado: a chave única do NIF recusa a duplicação (também a de empresas eliminadas).
            $falhou = true;
        }

        $this->assertTrue($falhou, 'O segundo registo com o mesmo NIF devia falhar.');
        $this->assertSame($antes, $contagens());
    }
}
