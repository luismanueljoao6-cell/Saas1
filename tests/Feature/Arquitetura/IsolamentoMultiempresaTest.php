<?php

namespace Tests\Feature\Arquitetura;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Models\PortfolioItem;
use Modules\Core\Http\Middleware\IdentificarTenant;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\EmpresaServico;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;
use Throwable;

/**
 * Fase 8 da auditoria da PR #33: isolamento multiempresa.
 * Os dois primeiros testes varrem o código; os restantes provam o comportamento.
 */
class IsolamentoMultiempresaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models com coluna empresa_id que, de propósito, NÃO usam BelongsToTenant.
     * Cada entrada exige justificação: classe => motivo.
     *
     * @var array<class-string, string>
     */
    private const MODELS_SEM_ESCOPO = [
        Perfil::class => 'Landing pública lê sem tenant; todas as consultas filtram por empresa_id (LandingController, PerfilController).',
        PortfolioItem::class => 'Landing pública lê sem tenant; o PortfolioController filtra por empresa e valida a posse no destruir.',
        EmpresaServico::class => 'Só acedido através de Empresa::servicos(), já filtrado pela empresa.',
        User::class => 'Autenticação sem tenant; consultas por ID filtram por empresa_id ou validam a posse (UtilizadorController, EquipaEstudioController).',
        AgtSubmissao::class => 'Só serviços e comandos de consola entre empresas; nunca exposto por HTTP (verificado em ficheiros PHP).',
    ];

    /**
     * Rotas autenticadas que, de propósito, não passam pelo middleware 'tenant'.
     * Nome da rota => motivo.
     *
     * @var array<string, string>
     */
    private const ROTAS_AUTENTICADAS_SEM_TENANT = [
        'core.logout' => 'Terminar a sessão tem de funcionar mesmo sem empresa (super admin, utilizador desativado).',
    ];

    /**
     * Ações que recebem por binding implícito um model SEM escopo de tenant: o binding não
     * filtra por empresa, por isso cada uma valida a posse à mão. Classe@método => motivo.
     *
     * @var array<string, string>
     */
    private const BINDINGS_SEM_ESCOPO = [
        'Modules\Core\Http\Controllers\UtilizadorController@alternarAtivo' => 'User: abort_unless(empresa do admin == empresa do utilizador) e nova consulta com where(empresa_id).',
        'Modules\Atelier\Http\Controllers\Admin\PortfolioController@destruir' => 'PortfolioItem: abort_unless(empresa_id do item == empresa do utilizador).',
    ];

    public function test_todo_model_com_empresa_id_usa_belongs_to_tenant(): void
    {
        $violacoes = [];
        $inspecionados = 0;

        foreach ($this->modelsDosModulos() as $classe) {
            try {
                $tabela = (new $classe)->getTable();
            } catch (Throwable) {
                continue;
            }

            if (! Schema::hasTable($tabela) || ! Schema::hasColumn($tabela, 'empresa_id')) {
                continue;
            }

            $inspecionados++;

            if (array_key_exists($classe, self::MODELS_SEM_ESCOPO)) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($classe), true)) {
                $violacoes[] = "{$classe} (tabela {$tabela})";
            }
        }

        $this->assertGreaterThan(0, $inspecionados, 'Nenhum model com empresa_id inspecionado: o teste ficou vazio.');
        $this->assertSame([], $violacoes, "Models com empresa_id sem BelongsToTenant:\n".implode("\n", $violacoes));
    }

    public function test_rotas_autenticadas_dos_modulos_passam_pelo_middleware_de_tenant(): void
    {
        $violacoes = [];
        $inspecionadas = 0;

        foreach (Route::getRoutes() as $rota) {
            if (! str_starts_with(ltrim($rota->getActionName(), '\\'), 'Modules\\')) {
                continue;
            }

            $middleware = array_filter(app('router')->gatherRouteMiddleware($rota), 'is_string');

            $autenticada = collect($middleware)->contains(
                fn (string $m): bool => str_starts_with($m, 'Illuminate\\Auth\\Middleware\\Authenticate')
            );

            if (! $autenticada) {
                continue;
            }

            $inspecionadas++;

            $comTenant = collect($middleware)->contains(
                fn (string $m): bool => str_starts_with($m, IdentificarTenant::class)
            );

            if ($comTenant || array_key_exists((string) $rota->getName(), self::ROTAS_AUTENTICADAS_SEM_TENANT)) {
                continue;
            }

            $violacoes[] = implode('|', $rota->methods()).' /'.$rota->uri().' ['.($rota->getName() ?? 'sem nome').']';
        }

        $this->assertGreaterThan(0, $inspecionadas, 'Nenhuma rota autenticada inspecionada: o teste ficou vazio.');
        $this->assertSame([], $violacoes, "Rotas autenticadas sem middleware 'tenant':\n".implode("\n", $violacoes));
    }

    public function test_binding_implicito_de_models_sem_escopo_so_em_acoes_com_validacao_de_posse(): void
    {
        $violacoes = [];

        foreach (Route::getRoutes() as $rota) {
            $acao = ltrim($rota->getActionName(), '\\');

            if (! str_starts_with($acao, 'Modules\\') || ! str_contains($acao, '@')) {
                continue;
            }

            [$controller, $metodo] = explode('@', $acao, 2);

            if (! method_exists($controller, $metodo)) {
                continue;
            }

            foreach ((new ReflectionMethod($controller, $metodo))->getParameters() as $parametro) {
                $tipo = $parametro->getType();

                if (! $tipo instanceof ReflectionNamedType || $tipo->isBuiltin() || ! is_subclass_of($tipo->getName(), Model::class)) {
                    continue;
                }

                $modelo = $tipo->getName();

                if ($modelo === Empresa::class || in_array(BelongsToTenant::class, class_uses_recursive($modelo), true)) {
                    continue;
                }

                $chave = $controller.'@'.$metodo;

                if (! array_key_exists($chave, self::BINDINGS_SEM_ESCOPO)) {
                    $violacoes[$chave.' ('.class_basename($modelo).')'] = true;
                }
            }
        }

        $this->assertSame([], array_keys($violacoes), "Ações com binding implícito de model sem escopo de tenant (validar a posse e registar em BINDINGS_SEM_ESCOPO):\n".implode("\n", array_keys($violacoes)));
    }

    public function test_sem_tenant_e_sem_bypass_nao_se_le_nada(): void
    {
        [$a, $b] = $this->duasEmpresas();
        $this->clienteDe($a);
        $this->clienteDe($b);

        app(TenantManager::class)->clear();

        $this->assertSame(0, Cliente::query()->count());
    }

    public function test_com_tenant_so_se_veem_os_proprios_registos(): void
    {
        [$a, $b] = $this->duasEmpresas();
        $this->clienteDe($a);
        $doB = $this->clienteDe($b);

        app(TenantManager::class)->set($a->id);

        $this->assertSame(1, Cliente::query()->count());
        $this->assertNull(Cliente::query()->find($doB->id));
    }

    public function test_empresa_id_forjado_na_criacao_e_substituido_pelo_tenant(): void
    {
        [$a, $b] = $this->duasEmpresas();

        app(TenantManager::class)->set($a->id);

        $cliente = (new Cliente)->forceFill(['empresa_id' => $b->id, 'nome' => 'Forjado']);
        $cliente->save();

        $this->assertSame($a->id, (int) $cliente->fresh()->empresa_id);
    }

    public function test_registo_carregado_em_bypass_nao_e_alterado_nem_apagado_por_outro_tenant(): void
    {
        [$a, $b] = $this->duasEmpresas();
        $doB = $this->clienteDe($b);
        $tenants = app(TenantManager::class);

        $carregado = $tenants->semTenant(fn () => Cliente::query()->find($doB->id));
        $this->assertNotNull($carregado);

        $tenants->set($a->id);

        try {
            $carregado->nome = 'Alterado por outra empresa';
            $carregado->save();
            $this->fail('A alteração de um registo de outra empresa devia ter sido recusada.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $carregado->delete();
            $this->fail('A eliminação de um registo de outra empresa devia ter sido recusada.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $tenants->clear();
        $this->assertSame('Cliente '.$b->id, $tenants->semTenant(fn () => Cliente::query()->find($doB->id))->nome);
    }

    /** @return array{0: Empresa, 1: Empresa} */
    private function duasEmpresas(): array
    {
        return [
            Empresa::create(['nome_comercial' => 'Empresa Isolamento A', 'nif' => '510009001']),
            Empresa::create(['nome_comercial' => 'Empresa Isolamento B', 'nif' => '510009002']),
        ];
    }

    private function clienteDe(Empresa $empresa): Cliente
    {
        return app(TenantManager::class)->semTenant(function () use ($empresa): Cliente {
            $cliente = (new Cliente)->forceFill(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$empresa->id]);
            $cliente->save();

            return $cliente;
        });
    }

    /** @return array<int, class-string<Model>> */
    private function modelsDosModulos(): array
    {
        $classes = [];

        foreach (glob(base_path('Modules/*/app/Models')) ?: [] as $pasta) {
            $modulo = basename(dirname($pasta, 2));
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->getExtension() !== 'php') {
                    continue;
                }

                $relativo = substr($ficheiro->getPathname(), strlen($pasta) + 1, -4);
                $classe = 'Modules\\'.$modulo.'\\Models\\'.str_replace(['/', '\\'], '\\', $relativo);

                if (! class_exists($classe)) {
                    continue;
                }

                $reflexao = new ReflectionClass($classe);

                if ($reflexao->isAbstract() || ! $reflexao->isSubclassOf(Model::class)) {
                    continue;
                }

                $classes[] = $classe;
            }
        }

        sort($classes);

        return $classes;
    }
}
