<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Container\Container;
use Modules\Core\Contracts\ModuleRegistryInterface;
use Modules\Core\Services\ModuleRegistry;
use Modules\Core\Services\TenantProvisionerRegistry;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    /** @param  array<int, string>  $requer */
    private function modulo(string $nome, bool $ativo = true, array $requer = ['Core'], ?string $servico = null): object
    {
        return new class($nome, $ativo, $requer, $servico)
        {
            public function __construct(
                private string $nome,
                private bool $ativo,
                private array $requer,
                private ?string $servico,
            ) {}

            public function getName(): string
            {
                return $this->nome;
            }

            public function isEnabled(): bool
            {
                return $this->ativo;
            }

            public function get(string $chave, mixed $padrao = null): mixed
            {
                return match ($chave) {
                    'requires' => $this->requer,
                    'servico' => $this->servico,
                    default => $padrao,
                };
            }
        };
    }

    /** @param  array<int, object>  $modulos */
    private function registoCom(array $modulos, ?TenantProvisionerRegistry $provisionadores = null): ModuleRegistry
    {
        $contentor = new Container;
        $contentor->instance('modules', new class($modulos)
        {
            public function __construct(private array $modulos) {}

            public function all(): array
            {
                return $this->modulos;
            }
        });

        return new ModuleRegistry($contentor, $provisionadores ?? new TenantProvisionerRegistry);
    }

    /** Conjunto coerente: serve de ponto de partida para cada falha. */
    private function coerentes(): array
    {
        return [
            $this->modulo('Core', requer: []),
            $this->modulo('Faturacao', servico: 'faturacao'),
            $this->modulo('Atelier', requer: ['Core', 'Faturacao'], servico: 'atelier'),
            $this->modulo('EstudioMusica', requer: ['Core', 'Faturacao'], servico: 'estudio'),
        ];
    }

    public function test_os_modulos_reais_da_aplicacao_sao_coerentes(): void
    {
        $registo = $this->app->make(ModuleRegistryInterface::class);

        $this->assertSame([], $registo->validar());
        $this->assertSame('estudio', $registo->modulos()['EstudioMusica']['servico']);
        $this->assertSame(['Core', 'Faturacao'], $registo->dependencias('Atelier'));
        $this->assertTrue($registo->ativo('Core'));
    }

    public function test_conjunto_coerente_nao_tem_problemas(): void
    {
        $this->assertSame([], $this->registoCom($this->coerentes())->validar());
    }

    public function test_dependencia_em_falta_ou_desativada(): void
    {
        $modulos = $this->coerentes();
        $modulos[] = $this->modulo('Armazem', requer: ['Core', 'Inexistente']);
        $modulos[1] = $this->modulo('Faturacao', ativo: false, servico: 'faturacao');

        $problemas = $this->registoCom($modulos)->validar();

        $this->assertContains('O módulo [Armazem] requer [Inexistente], que não está instalado.', $problemas);
        $this->assertContains('O módulo [Atelier] requer [Faturacao], que está desativado.', $problemas);
        $this->assertContains('O serviço [faturacao] depende do módulo [Faturacao], que está desativado.', $problemas);
    }

    public function test_modulo_sem_dependencia_do_core(): void
    {
        $modulos = $this->coerentes();
        $modulos[] = $this->modulo('Armazem', requer: ['Faturacao']);

        $this->assertContains(
            'O módulo [Armazem] não declara a dependência do Core.',
            $this->registoCom($modulos)->validar(),
        );
    }

    public function test_dependencia_circular(): void
    {
        $modulos = [
            $this->modulo('Core', requer: []),
            $this->modulo('A', requer: ['Core', 'B']),
            $this->modulo('B', requer: ['Core', 'A']),
        ];

        $problemas = $this->registoCom($modulos)->validar();

        $this->assertContains('Dependência circular entre módulos: A → B → A.', $problemas);
    }

    public function test_servico_desconhecido_duplicado_ou_sem_modulo(): void
    {
        $modulos = [
            $this->modulo('Core', requer: []),
            $this->modulo('Faturacao', servico: 'faturacao'),
            $this->modulo('Outro', servico: 'faturacao'),
            $this->modulo('Estranho', servico: 'nao-existe'),
        ];

        $problemas = $this->registoCom($modulos)->validar();

        $this->assertContains('O módulo [Estranho] declara o serviço [nao-existe], que não existe em Servico.', $problemas);
        $this->assertContains('O serviço [faturacao] é declarado por mais do que um módulo ([Faturacao] e [Outro]).', $problemas);
        $this->assertContains('O serviço [atelier] não tem nenhum módulo que o declare ("servico" no module.json).', $problemas);
    }

    public function test_dependencia_de_servico_tem_de_estar_no_requires(): void
    {
        $modulos = $this->coerentes();
        $modulos[2] = $this->modulo('Atelier', requer: ['Core'], servico: 'atelier');

        $this->assertContains(
            'O serviço [atelier] inclui [faturacao], mas o módulo [Atelier] não requer o módulo [Faturacao].',
            $this->registoCom($modulos)->validar(),
        );
    }

    public function test_provisionador_ligado_a_servico_inexistente(): void
    {
        $provisionadores = new TenantProvisionerRegistry;
        $provisionadores->register('estudio', fn (int $id) => null);
        $provisionadores->register('armazem', fn (int $id) => null, servico: 'faturacao');
        $provisionadores->register('fantasma', fn (int $id) => null);

        $problemas = $this->registoCom($this->coerentes(), $provisionadores)->validar();

        $this->assertSame(
            ['O provisionador [fantasma] está ligado ao serviço [fantasma], que não existe: nunca correria ao ativar serviços.'],
            $problemas,
        );
    }
}
