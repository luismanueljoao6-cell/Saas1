<?php

namespace Modules\Atelier\Tests\Feature;

use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\CampanhaReenvio;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * ATL-01: oito pedidos de reenvio em PROCESSOS independentes, libertados ao mesmo tempo.
 * Só um pode ser aceite; a garantia final é o índice único na base de dados.
 * Sem RefreshDatabase de propósito: os dados têm de estar comprometidos para os
 * outros processos os verem. Ver cabeçalho do script da fase 4 (segurança).
 */
#[Group('concorrencia-real')]
class ReenvioCampanhaConcorrenciaRealTest extends TestCase
{
    use ProcessosConcorrentes;

    protected Empresa $empresa;

    protected User $admin;

    protected Campanha $campanha;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $sufixo = uniqid();

        $this->empresa = Empresa::create(['nome_comercial' => "Atelier Concorrência {$sufixo}", 'nif' => (string) random_int(400000000, 499999999)]);
        $this->empresa->aderirServicos(['atelier']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->admin = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Admin Concorrência',
            'email' => "admin.concorrencia.{$sufixo}@example.test",
            'password' => bcrypt('segredo-123'),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
        Role::create(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]);
        $this->admin->assignRole('Administrador');

        $cliente = Cliente::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Cliente Concorrência',
            'telefone' => '923456789',
        ]);
        ClienteCrm::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $cliente->id,
            'aceita_marketing' => true,
            'consentimento_em' => now()->subDay(),
        ]);

        $this->campanha = Campanha::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Campanha concorrência',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'concluida_com_falhas',
            'enviada_em' => now()->subHour(),
        ]);
        CampanhaEnvio::create([
            'campanha_id' => $this->campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => 'whatsapp',
            'estado' => CampanhaEnvio::ESTADO_FALHOU,
            'erro' => 'Falha anterior',
        ]);
    }

    public function test_oito_processos_simultaneos_aceitam_exatamente_um_reenvio(): void
    {
        $argumentos = array_fill(0, 8, [
            'empresa_id' => $this->empresa->id,
            'campanha_id' => $this->campanha->id,
            'user_id' => $this->admin->id,
        ]);

        $resultados = $this->correrEmParalelo('reenvio', $argumentos);

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Um processo falhou: '.($r['erro'] ?? '?'));
            $this->assertNull($r['erro'], 'Num clique duplo a resposta nunca pode ser um erro.');
        }

        $aceites = array_filter($resultados, fn ($r) => str_contains((string) $r['sucesso'], 'Envio iniciado'));
        $recusados = array_filter($resultados, fn ($r) => str_contains((string) $r['sucesso'], 'Já existe um reenvio em curso'));

        $this->assertCount(1, $aceites, 'Exatamente um pedido tem de ser aceite.');
        $this->assertCount(7, $recusados, 'Os restantes sete têm de receber "já existe um reenvio em curso".');
        $this->assertSame(1, array_sum(array_column($resultados, 'despachados')), 'Só um job pode ser despachado.');

        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->where('aberto', true)->count());
    }
}
