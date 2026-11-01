<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\User;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';

/** Destaque do portfólio na landing pública (só Administrador) e pequenos pormenores da ficha do artista. */
class PortfolioEPerfilTest extends TestCase
{
    use ComEstudioAutenticado;
    use RefreshDatabase;

    protected User $admin;

    protected ProjetoMusical $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->registarEstudio('510000401');
        $this->noTenantDe($this->admin);

        $cliente = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Artista Portfólio']);
        $this->projeto = ProjetoMusical::create([
            'empresa_id' => $this->admin->empresa_id,
            'cliente_id' => $cliente->id,
            'nome' => 'Álbum Vitrine',
            'tipo_cobranca' => 'hora',
        ]);
    }

    protected function rotaPortfolio(): string
    {
        return route('estudiomusica.projetos.portfolio', $this->projeto->id);
    }

    public function test_administrador_marca_o_destaque_e_a_landing_passa_a_mostra_lo(): void
    {
        $landing = route('estudiomusica.landing.mostrar', $this->admin->empresa_id);

        $this->get($landing)->assertOk()->assertDontSee('Álbum Vitrine');

        $this->actingAs($this->admin)
            ->put($this->rotaPortfolio(), [
                'destaque_portfolio' => 1,
                'link_publico' => 'https://open.spotify.com/album/abc123',
                'autorizacao_artista' => 1,
            ])
            ->assertSessionHas('sucesso');

        $this->get($landing)->assertOk()->assertSee('Álbum Vitrine');

        $this->actingAs($this->admin)
            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 0, 'link_publico' => 'https://open.spotify.com/album/abc123'])
            ->assertSessionHas('sucesso');

        $this->get($landing)->assertOk()->assertDontSee('Álbum Vitrine');
    }

    public function test_destaque_exige_link_https_e_a_autorizacao_do_artista(): void
    {
        $this->actingAs($this->admin)
            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'autorizacao_artista' => 1])
            ->assertSessionHasErrors('link_publico');

        $this->actingAs($this->admin)
            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'http://exemplo.test/faixa', 'autorizacao_artista' => 1])
            ->assertSessionHasErrors('link_publico');

        $this->actingAs($this->admin)
            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'javascript:alert(1)', 'autorizacao_artista' => 1])
            ->assertSessionHasErrors('link_publico');

        $this->actingAs($this->admin)
            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'https://open.spotify.com/album/abc123'])
            ->assertSessionHasErrors('autorizacao_artista');

        $this->noTenantDe($this->admin);
        $this->assertFalse((bool) $this->projeto->fresh()->destaque_portfolio);
    }

    public function test_so_o_administrador_gere_o_portfolio_e_so_ele_ve_o_cartao(): void
    {
        $colega = User::create([
            'empresa_id' => $this->admin->empresa_id,
            'name' => 'Receção',
            'email' => 'rececao@example.test',
            'password' => Hash::make('Senha-segura-123'),
        ]);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.equipa.atribuir'), ['utilizador_id' => $colega->id, 'papel' => 'Recepcao'])
            ->assertRedirect();

        $this->actingAs($colega)
            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'https://open.spotify.com/album/abc', 'autorizacao_artista' => 1])
            ->assertForbidden();

        $this->actingAs($colega)->get(route('estudiomusica.projetos.show', $this->projeto->id))
            ->assertOk()->assertDontSee('Portfólio na página pública');

        $this->actingAs($this->admin)->get(route('estudiomusica.projetos.show', $this->projeto->id))
            ->assertOk()->assertSee('Portfólio na página pública');
    }

    public function test_ficha_do_artista_aceita_campos_de_integrantes_em_branco(): void
    {
        // Os campos vazios chegam como null (ConvertEmptyStringsToNull): antes recusava o formulário inteiro.
        $this->actingAs($this->admin)
            ->put(route('estudiomusica.clientes.perfil-artista', $this->projeto->cliente_id), [
                'nome_artistico' => 'Banda Teste',
                'integrantes_banda' => ['Ana', '', null, 'Bruno'],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso');
    }
}
