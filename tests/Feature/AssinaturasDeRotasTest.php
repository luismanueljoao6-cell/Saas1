<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * O Laravel passa os parâmetros de rota aos métodos dos controllers POR
 * POSIÇÃO. Se a rota é `portal/{token}/versoes/{versao}` e o método só
 * declara `int $versao`, o token cai em `$versao` e o pedido rebenta com
 * TypeError (HTTP 500). Este teste percorre todas as rotas com controller de
 * todos os módulos e garante que a assinatura acompanha os parâmetros da rota.
 */
class AssinaturasDeRotasTest extends TestCase
{
    public function test_assinaturas_dos_controllers_acompanham_os_parametros_das_rotas(): void
    {
        $incoerentes = [];

        foreach (Route::getRoutes() as $rota) {
            $acao = $rota->getActionName();

            if ($acao === 'Closure' || ! str_contains($acao, '@')) {
                continue;
            }

            [$classe, $metodo] = explode('@', $acao);

            if (! class_exists($classe) || ! method_exists($classe, $metodo)) {
                $incoerentes[] = "{$rota->uri()} -> {$acao} (método inexistente)";

                continue;
            }

            $parametrosDaRota = $rota->parameterNames();
            $proprios = [];

            foreach ((new ReflectionMethod($classe, $metodo))->getParameters() as $parametro) {
                $tipo = $parametro->getType();
                $injetado = false;

                if ($tipo instanceof ReflectionNamedType && ! $tipo->isBuiltin()) {
                    // Serviços e Request vêm do contentor; models (binding
                    // implícito) e parâmetros com o nome de um parâmetro da
                    // rota vêm da própria rota.
                    $injetado = ! is_subclass_of($tipo->getName(), Model::class)
                        && ! in_array($parametro->getName(), $parametrosDaRota, true);
                }

                if (! $injetado) {
                    $proprios[] = $parametro->getName();
                }
            }

            if ($proprios !== array_slice($parametrosDaRota, 0, count($proprios))) {
                $incoerentes[] = sprintf(
                    '%s %s -> %s  (rota: [%s] | método: [%s])',
                    implode('|', $rota->methods()),
                    $rota->uri(),
                    $acao,
                    implode(', ', $parametrosDaRota),
                    implode(', ', $proprios),
                );
            }
        }

        $this->assertSame(
            [],
            $incoerentes,
            "Assinaturas incoerentes com os parâmetros da rota:\n".implode("\n", $incoerentes),
        );
    }
}
