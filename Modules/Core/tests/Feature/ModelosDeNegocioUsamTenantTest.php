<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Traits\BelongsToTenant;
use ReflectionClass;
use Tests\TestCase;

/**
 * Rede de segurança contra o esquecimento da trait: qualquer model dos
 * módulos cuja tabela tenha `empresa_id` tem de usar BelongsToTenant, a
 * menos que esteja nas exceções abaixo (cada uma com a sua justificação).
 * Um model novo sem a trait falha aqui em vez de vazar dados entre empresas.
 */
class ModelosDeNegocioUsamTenantTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, string> */
    private const EXCECOES = [
        \Modules\Core\Models\User::class => 'O login procura o utilizador antes de haver tenant; o isolamento é feito nos controllers.',
        \Modules\Core\Models\EmpresaServico::class => 'É lida antes de haver tenant (registo, menu); o isolamento faz-se através da Empresa.',
        \Modules\Atelier\Models\Perfil::class => 'Lido sem tenant pela landing pública; a área admin filtra por empresa_id do utilizador e exige Administrador.',
        \Modules\Atelier\Models\PortfolioItem::class => 'Lido sem tenant pela landing pública; Admin\PortfolioController filtra por empresa_id e verifica posse e papel.',
        \Modules\Faturacao\Models\AgtSubmissao::class => 'Acedida pela relação agtSubmissao() dos documentos (já com TenantScope) e por comandos/jobs entre empresas.',
    ];

    public function test_todo_o_model_com_empresa_id_usa_belongs_to_tenant(): void
    {
        $semTrait = [];
        $analisados = 0;

        foreach ($this->modelosDosModulos() as $classe) {
            $modelo = new $classe;

            if (! Schema::hasColumn($modelo->getTable(), 'empresa_id')) {
                continue;
            }

            $analisados++;

            if (array_key_exists($classe, self::EXCECOES)) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($classe), true)) {
                $semTrait[] = $classe;
            }
        }

        $this->assertGreaterThan(0, $analisados, 'Nenhum model com empresa_id foi analisado — a descoberta de models falhou.');

        $this->assertSame(
            [],
            $semTrait,
            "Models com empresa_id sem a trait BelongsToTenant (usa a trait ou justifica em EXCECOES):\n".implode("\n", $semTrait)
        );
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelosDosModulos(): array
    {
        $classes = [];

        foreach (glob(base_path('Modules/*/app/Models/*.php')) ?: [] as $ficheiro) {
            $modulo = basename(dirname($ficheiro, 3));
            $classe = "Modules\\{$modulo}\\Models\\".basename($ficheiro, '.php');

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class)) {
                continue;
            }

            if ((new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $classes[] = $classe;
        }

        return $classes;
    }
}
