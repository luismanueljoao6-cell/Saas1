<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Modules\EstudioMusica\Models\ProjetoMusical;
use Tests\TestCase;

class IncorporacaoPublicaTest extends TestCase
{
    protected function projetoComLink(?string $link): ProjetoMusical
    {
        return (new ProjetoMusical)->forceFill(['link_publico' => $link]);
    }

    public function test_youtube_watch_vira_embed(): void
    {
        $r = $this->projetoComLink('https://www.youtube.com/watch?v=dQw4w9WgXcQ')->incorporacaoPublica();

        $this->assertSame(['tipo' => 'youtube', 'url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ'], $r);
    }

    public function test_spotify_vira_embed(): void
    {
        $r = $this->projetoComLink('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC?si=x')->incorporacaoPublica();

        $this->assertSame(['tipo' => 'spotify', 'url' => 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC'], $r);
    }

    public function test_esquemas_perigosos_e_dominios_disfarcados_nao_viram_iframe(): void
    {
        $this->assertNull($this->projetoComLink("javascript:alert('youtube.com')")->incorporacaoPublica());
        $this->assertNull($this->projetoComLink('http://youtube.com/watch?v=dQw4w9WgXcQ')->incorporacaoPublica());

        // Contém "spotify.com" mas o host é outro: nunca é embed.
        $r = $this->projetoComLink('https://evil.example/?x=open.spotify.com')->incorporacaoPublica();
        $this->assertSame('link', $r['tipo']);
    }
}
