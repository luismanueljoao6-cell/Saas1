<?php

namespace Modules\Core\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Convite;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ConviteDeUtilizador;
use Modules\Core\Services\ConviteService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConvitesServicoTest extends TestCase
{
    use RefreshDatabase;

    protected const SENHA = 'Senha-Forte-123!';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function criarEmpresa(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
    }

    protected function criarAdmin(Empresa $empresa): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Admin Teste',
            'email' => uniqid('a').'@teste.ao',
            'password' => self::SENHA,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        return $utilizador;
    }

    protected function definirLimite(?int $limite): void
    {
        $this->app->bind(LimitesDaEmpresa::class, fn () => new class($limite) implements LimitesDaEmpresa
        {
            public function __construct(protected ?int $limite) {}

            public function limite(Empresa $empresa, string $chave): ?int
            {
                return $chave === 'max_utilizadores' ? $this->limite : null;
            }
        });
    }

    /** Resolve de novo de cada vez, para apanhar o limite definido no teste. */
    protected function servico(): ConviteService
    {
        return app(ConviteService::class);
    }

    /** Token em claro do último e-mail de convite enviado. */
    protected function tokenEnviado(): string
    {
        $token = null;

        Notification::assertSentOnDemand(ConviteDeUtilizador::class, function (ConviteDeUtilizador $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    protected function recusado(callable $acao): void
    {
        try {
            $acao();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Era esperada uma DomainException.');
    }

    public function test_convidar_guarda_so_o_hash_e_envia_o_token_por_email(): void
    {
        $admin = $this->criarAdmin($this->criarEmpresa('510000001'));

        $convite = $this->servico()->convidar($admin, 'Novo@Teste.ao', 'Novo Colega', false);
        $token = $this->tokenEnviado();

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $convite->token_hash);
        $this->assertDatabaseMissing('convites', ['token_hash' => $token]);
        $this->assertDatabaseHas('convites', ['email' => 'novo@teste.ao', 'empresa_id' => $admin->empresa_id]);

        Notification::assertSentOnDemand(
            ConviteDeUtilizador::class,
            fn ($n, $canais, $notifiable) => $notifiable->routes['mail'] === 'novo@teste.ao'
        );
    }

    public function test_email_ja_registado_e_recusado(): void
    {
        $admin = $this->criarAdmin($this->criarEmpresa('510000002'));

        $this->recusado(fn () => $this->servico()->convidar($admin, $admin->email, null, false));

        $this->assertDatabaseCount('convites', 0);
    }

    public function test_limite_do_plano_conta_convites_pendentes(): void
    {
        $admin = $this->criarAdmin($this->criarEmpresa('510000003'));
        $this->definirLimite(2); // o admin ocupa 1 vaga, o primeiro convite a outra

        $this->servico()->convidar($admin, 'a@teste.ao', null, false);
        $this->recusado(fn () => $this->servico()->convidar($admin, 'b@teste.ao', null, false));

        $this->assertDatabaseMissing('convites', ['email' => 'b@teste.ao']);
    }

    public function test_reemitir_substitui_o_convite_anterior(): void
    {
        $admin = $this->criarAdmin($this->criarEmpresa('510000004'));

        $this->servico()->convidar($admin, 'novo@teste.ao', null, false);
        $antigo = $this->tokenEnviado();
        $this->servico()->convidar($admin, 'novo@teste.ao', null, false);
        $novo = $this->tokenEnviado();

        $this->assertNotSame($antigo, $novo);
        $this->assertSame(1, Convite::withoutGlobalScopes()->where('email', 'novo@teste.ao')->pendentes()->count());
        $this->recusado(fn () => $this->servico()->aceitar($antigo, 'Novo', self::SENHA));
        $this->assertNotNull($this->servico()->aceitar($novo, 'Novo', self::SENHA));
    }

    public function test_aceitar_cria_o_utilizador_na_empresa_certa_com_papel_e_email_verificado(): void
    {
        $empresa = $this->criarEmpresa('510000005');
        $admin = $this->criarAdmin($empresa);

        $this->servico()->convidar($admin, 'novo@teste.ao', null, true);
        $utilizador = $this->servico()->aceitar($this->tokenEnviado(), 'Novo Colega', self::SENHA);

        $this->assertSame((int) $empresa->id, (int) $utilizador->empresa_id);
        $this->assertSame('novo@teste.ao', $utilizador->email);
        $this->assertTrue($utilizador->hasVerifiedEmail());
        $this->assertTrue(Hash::check(self::SENHA, $utilizador->password));

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $this->assertTrue($utilizador->fresh()->hasRole('Administrador'));
        $this->assertNotNull(Convite::withoutGlobalScopes()->first()->aceite_em);
    }

    public function test_o_token_so_pode_ser_usado_uma_vez(): void
    {
        $admin = $this->criarAdmin($this->criarEmpresa('510000006'));

        $this->servico()->convidar($admin, 'novo@teste.ao', null, false);
        $token = $this->tokenEnviado();

        $this->servico()->aceitar($token, 'Novo', self::SENHA);
        $this->recusado(fn () => $this->servico()->aceitar($token, 'Outro', self::SENHA));

        $this->assertSame(1, User::where('email', 'novo@teste.ao')->count());
    }

    public function test_convite_expirado_ou_revogado_e_recusado(): void
    {
        $empresa = $this->criarEmpresa('510000007');
        $admin = $this->criarAdmin($empresa);

        $convite = $this->servico()->convidar($admin, 'expira@teste.ao', null, false);
        $tokenExpirado = $this->tokenEnviado();
        Convite::withoutGlobalScopes()->whereKey($convite->id)->update(['expires_at' => now()->subMinute()]);

        $revogado = $this->servico()->convidar($admin, 'revoga@teste.ao', null, false);
        $tokenRevogado = $this->tokenEnviado();
        $this->servico()->revogar($admin, $revogado);

        $this->recusado(fn () => $this->servico()->aceitar($tokenExpirado, 'X', self::SENHA));
        $this->recusado(fn () => $this->servico()->aceitar($tokenRevogado, 'X', self::SENHA));
        $this->assertDatabaseMissing('users', ['email' => 'expira@teste.ao']);
        $this->assertDatabaseMissing('users', ['email' => 'revoga@teste.ao']);
    }

    public function test_admin_de_outra_empresa_nao_revoga_o_convite(): void
    {
        $adminA = $this->criarAdmin($this->criarEmpresa('510000008'));
        $adminB = $this->criarAdmin($this->criarEmpresa('510000009'));

        $convite = $this->servico()->convidar($adminA, 'novo@teste.ao', null, false);

        $this->recusado(fn () => $this->servico()->revogar($adminB, $convite));
        $this->assertNull(Convite::withoutGlobalScopes()->find($convite->id)->revogado_em);
    }

    public function test_limite_e_verificado_outra_vez_na_aceitacao(): void
    {
        $admin = $this->criarAdmin($this->criarEmpresa('510000010'));
        $this->definirLimite(null);

        $this->servico()->convidar($admin, 'novo@teste.ao', null, false);
        $token = $this->tokenEnviado();

        $this->definirLimite(1); // entretanto o plano mudou: o admin já ocupa a única vaga
        $this->recusado(fn () => $this->servico()->aceitar($token, 'Novo', self::SENHA));

        $this->assertDatabaseMissing('users', ['email' => 'novo@teste.ao']);
    }
}
