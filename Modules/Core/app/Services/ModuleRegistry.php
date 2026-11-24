<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Core\Contracts\ModuleRegistryInterface;
use Modules\Core\Contracts\TenantProvisionerInterface;
use Modules\Core\Support\Servico;

/**
 * Lê os módulos do Nwidart (module.json + estado ativo) e confronta-os com o
 * enum Servico e com os provisionadores registados.
 */
class ModuleRegistry implements ModuleRegistryInterface
{
    public function __construct(
        private readonly Container $container,
        private readonly TenantProvisionerInterface $provisionadores,
    ) {}

    public function modulos(): array
    {
        $modulos = [];

        foreach ($this->container->make('modules')->all() as $modulo) {
            $servico = $modulo->get('servico');

            $modulos[$modulo->getName()] = [
                'nome' => $modulo->getName(),
                'ativo' => (bool) $modulo->isEnabled(),
                'requer' => array_values(array_map('strval', (array) $modulo->get('requires', []))),
                'servico' => $servico === null ? null : (string) $servico,
            ];
        }

        ksort($modulos);

        return $modulos;
    }

    public function ativo(string $modulo): bool
    {
        return $this->modulos()[$modulo]['ativo'] ?? false;
    }

    public function dependencias(string $modulo): array
    {
        return $this->modulos()[$modulo]['requer'] ?? [];
    }

    public function validar(): array
    {
        $modulos = $this->modulos();
        $problemas = [];

        foreach ($modulos as $nome => $m) {
            if (! $m['ativo']) {
                continue;
            }

            if ($nome !== 'Core' && ! in_array('Core', $m['requer'], true)) {
                $problemas[] = "O módulo [{$nome}] não declara a dependência do Core.";
            }

            foreach ($m['requer'] as $dependencia) {
                if (! isset($modulos[$dependencia])) {
                    $problemas[] = "O módulo [{$nome}] requer [{$dependencia}], que não está instalado.";
                } elseif (! $modulos[$dependencia]['ativo']) {
                    $problemas[] = "O módulo [{$nome}] requer [{$dependencia}], que está desativado.";
                }
            }
        }

        foreach ($this->ciclos($modulos) as $ciclo) {
            $problemas[] = 'Dependência circular entre módulos: '.implode(' → ', $ciclo).'.';
        }

        // Serviço -> módulo que o disponibiliza.
        $moduloDoServico = [];

        foreach ($modulos as $nome => $m) {
            if ($m['servico'] === null) {
                continue;
            }

            $servico = Servico::tryFrom($m['servico']);

            if ($servico === null) {
                $problemas[] = "O módulo [{$nome}] declara o serviço [{$m['servico']}], que não existe em Servico.";

                continue;
            }

            if (isset($moduloDoServico[$servico->value])) {
                $problemas[] = "O serviço [{$servico->value}] é declarado por mais do que um módulo ([{$moduloDoServico[$servico->value]}] e [{$nome}]).";

                continue;
            }

            $moduloDoServico[$servico->value] = $nome;
        }

        foreach (Servico::cases() as $servico) {
            $nome = $moduloDoServico[$servico->value] ?? null;

            if ($nome === null) {
                $problemas[] = "O serviço [{$servico->value}] não tem nenhum módulo que o declare (\"servico\" no module.json).";

                continue;
            }

            if (! $modulos[$nome]['ativo']) {
                $problemas[] = "O serviço [{$servico->value}] depende do módulo [{$nome}], que está desativado.";

                continue;
            }

            foreach ($servico->dependencias() as $dependencia) {
                $moduloDependencia = $moduloDoServico[$dependencia->value] ?? null;

                if ($moduloDependencia !== null && ! in_array($moduloDependencia, $modulos[$nome]['requer'], true)) {
                    $problemas[] = "O serviço [{$servico->value}] inclui [{$dependencia->value}], mas o módulo [{$nome}] não requer o módulo [{$moduloDependencia}].";
                }
            }
        }

        foreach ($this->provisionadores->modules() as $chave) {
            $servico = $this->provisionadores->servicoDe($chave);

            if ($servico === null || Servico::tryFrom($servico) === null) {
                $problemas[] = "O provisionador [{$chave}] está ligado ao serviço [".($servico ?? '—').'], que não existe: nunca correria ao ativar serviços.';
            }
        }

        return $problemas;
    }

    /**
     * @param  array<string, array{nome: string, ativo: bool, requer: array<int, string>, servico: string|null}>  $modulos
     * @return array<int, array<int, string>>
     */
    private function ciclos(array $modulos): array
    {
        $ciclos = [];
        $estado = [];
        $visitar = null;

        $visitar = function (string $nome, array $caminho) use (&$visitar, &$estado, &$ciclos, $modulos): void {
            if (($estado[$nome] ?? 0) === 2) {
                return;
            }

            if (($estado[$nome] ?? 0) === 1) {
                $inicio = (int) array_search($nome, $caminho, true);
                $ciclos[] = [...array_slice($caminho, $inicio), $nome];

                return;
            }

            $estado[$nome] = 1;

            foreach ($modulos[$nome]['requer'] as $dependencia) {
                if (isset($modulos[$dependencia])) {
                    $visitar($dependencia, [...$caminho, $nome]);
                }
            }

            $estado[$nome] = 2;
        };

        foreach (array_keys($modulos) as $nome) {
            $visitar($nome, []);
        }

        return $ciclos;
    }
}
