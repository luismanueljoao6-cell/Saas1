(
# Fase 3a — Facturação Electrónica AGT: cliente congelado no documento e QR Code.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[ -d Modules/Faturacao ] || { echo "ERRO: corre isto na raiz do projeto Saas1."; exit 1; }
git checkout main
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERRO: tens alterações por commitar. Faz commit ou 'git stash' primeiro."; exit 1
fi
git pull --ff-only origin main
[ -f Modules/Faturacao/app/Models/AgtSubmissao.php ] || { echo "ERRO: a Fase 2b ainda não está na main."; exit 1; }
trap 'echo; echo "FALHOU. Nada foi commitado. Para desfazer: git restore --staged --worktree . && git clean -fd -- Modules/Faturacao"' ERR

echo "== Dependência do QR Code (bacon/qr-code) =="
composer require bacon/qr-code --no-interaction
mkdir -p Modules/Faturacao/app/Support
cat > Modules/Faturacao/app/Support/QrCodeAgt.php <<'EOF_F3A_0'
<?php

namespace Modules\Faturacao\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * QR Code dos documentos impressos, segundo as especificações da AGT: URL
 * https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor={NIF}&document={documentNo}
 * (cada espaço do documentNo => %20), modelo 2, correção de erros M, modo byte
 * UTF-8, imagem PNG de 350x350 px.
 *
 * A AGT indica "versão 4 (33x33)", mas o URL tem cerca de 120 bytes e a versão 4
 * com correção M só comporta 62: usa-se a menor versão que o comporta.
 */
final class QrCodeAgt
{
    public const BASE = 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe';

    public const TAMANHO = 350;

    public static function url(string $nifEmissor, string $numeroDocumento): string
    {
        return self::BASE.'?emissor='.$nifEmissor.'&document='.str_replace(' ', '%20', $numeroDocumento);
    }

    /** PNG 350x350, ou null se a extensão GD não estiver disponível. */
    public static function png(string $url, int $tamanho = self::TAMANHO): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        [$matriz, $total, $modulo, $deslocamento] = self::geometria($url, $tamanho);

        $imagem = imagecreatetruecolor($tamanho, $tamanho);
        imagefill($imagem, 0, 0, (int) imagecolorallocate($imagem, 255, 255, 255));
        $preto = (int) imagecolorallocate($imagem, 0, 0, 0);
        $n = $matriz->getWidth();

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matriz->get($x, $y) === 1) {
                    $px = $deslocamento + $x * $modulo;
                    $py = $deslocamento + $y * $modulo;
                    imagefilledrectangle($imagem, $px, $py, $px + $modulo - 1, $py + $modulo - 1, $preto);
                }
            }
        }

        ob_start();
        imagepng($imagem);
        $png = (string) ob_get_clean();
        imagedestroy($imagem);

        return $png;
    }

    public static function svg(string $url, int $tamanho = self::TAMANHO): string
    {
        [$matriz, $total] = self::geometria($url, $tamanho);
        $n = $matriz->getWidth();
        $caminho = '';

        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if ($matriz->get($x, $y) !== 1) {
                    $x++;

                    continue;
                }

                $inicio = $x;
                while ($x < $n && $matriz->get($x, $y) === 1) {
                    $x++;
                }

                $caminho .= 'M'.($inicio + 4).' '.($y + 4).'h'.($x - $inicio).'v1h-'.($x - $inicio).'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$tamanho.'" height="'.$tamanho.'" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path fill="#000" d="'.$caminho.'"/></svg>';
    }

    /** data: URI para <img src>; PNG quando possível, senão SVG. */
    public static function dataUri(string $url): string
    {
        $png = self::png($url);

        return $png !== null
            ? 'data:image/png;base64,'.base64_encode($png)
            : 'data:image/svg+xml;base64,'.base64_encode(self::svg($url));
    }

    /** @return array{0: \BaconQrCode\Encoder\ByteMatrix, 1: int, 2: int, 3: int} matriz, módulos totais (com margem), px por módulo, deslocamento */
    protected static function geometria(string $url, int $tamanho): array
    {
        $codigo = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8', null, false);
        $matriz = $codigo->getMatrix();
        $total = $matriz->getWidth() + 8; // margem de 4 módulos (quiet zone)
        $modulo = intdiv($tamanho, $total);

        if ($modulo < 1) {
            throw new RuntimeException('Tamanho do QR Code demasiado pequeno.');
        }

        $deslocamento = intdiv($tamanho - $modulo * $total, 2) + 4 * $modulo;

        return [$matriz, $total, $modulo, $deslocamento];
    }
}
EOF_F3A_0
mkdir -p Modules/Faturacao/app/Traits
cat > Modules/Faturacao/app/Traits/AssinavelAgt.php <<'EOF_F3A_1'
<?php

namespace Modules\Faturacao\Traits;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\PayloadAssinaturaAgt;
use Modules\Faturacao\Support\QrCodeAgt;

/**
 * Constrói o objecto JSON canónico assinado em jwsDocumentSignature para os
 * documentos fiscais (Fatura, NotaCreditoDebito, Recibo). Lê empresa e cliente
 * sem depender do tenant ambiente (emissão corre em jobs, comandos e testes).
 */
trait AssinavelAgt
{
    /**
     * Com a comunicação à AGT ativa, cada documento emitido fica registado para
     * envio — na mesma transação da emissão, por isso nunca há um sem o outro.
     */
    public static function bootAssinavelAgt(): void
    {
        static::saved(function ($documento): void {
            if (config('faturacao.agt.ativo') && $documento->estaEmitido() && $documento->wasChanged('hash')) {
                AgtSubmissao::query()->firstOrCreate(
                    ['documento_type' => $documento->getMorphClass(), 'documento_id' => $documento->getKey()],
                    ['empresa_id' => $documento->empresa_id, 'documento_no' => (string) $documento->numero_documento, 'estado' => AgtSubmissao::PENDENTE],
                );
            }
        });
    }

    /**
     * Congela no documento o NIF, o nome e o país do cliente. Chamado pelo
     * serviço de assinatura no momento da emissão, antes de construir o objecto
     * assinado — daí em diante o documento não depende do registo do cliente.
     */
    public function congelarCliente(): void
    {
        $cliente = $this->clienteAtual();

        $this->cliente_nif = $cliente->nif;
        $this->cliente_nome = $cliente->nome;
        $this->cliente_pais = $cliente->pais ?: 'AO';
    }

    /** URL do QR Code AGT (consultar-fe), a partir do que foi assinado; null se ainda não emitido. */
    public function urlQrAgt(): ?string
    {
        $assinado = json_decode((string) Jws::payload((string) $this->hash), true);

        if (! is_array($assinado) || ! isset($assinado['taxRegistrationNumber'], $assinado['documentNo'])) {
            return null;
        }

        return QrCodeAgt::url((string) $assinado['taxRegistrationNumber'], (string) $assinado['documentNo']);
    }

    protected function clienteAtual(): Cliente
    {
        $cliente = Cliente::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->find($this->cliente_id);

        if (! $cliente) {
            throw AssinaturaFiscalException::dadosEmFalta('O cliente do documento não pertence a esta empresa.');
        }

        return $cliente;
    }

    protected function payloadAgtJson(int $impostoCentimos, int $liquidoCentimos, int $brutoCentimos): string
    {
        $empresa = Empresa::query()->find($this->empresa_id);

        if (! $empresa || trim((string) $empresa->nif) === '') {
            throw AssinaturaFiscalException::dadosEmFalta('A empresa emissora não tem NIF configurado.');
        }

        // Documentos emitidos usam os dados congelados; os anteriores a esta
        // funcionalidade (sem congelamento) usam os dados atuais do cliente.
        if ($this->cliente_nome !== null) {
            [$nif, $nome, $pais] = [$this->cliente_nif, $this->cliente_nome, $this->cliente_pais];
        } else {
            $cliente = $this->clienteAtual();
            [$nif, $nome, $pais] = [$cliente->nif, $cliente->nome, $cliente->pais];
        }

        return PayloadAssinaturaAgt::json(
            numeroDocumento: (string) $this->numero_documento,
            nifEmissor: (string) $empresa->nif,
            tipoDocumento: $this->tipoDocumentoFiscal(),
            dataDocumento: $this->data_emissao->format('Y-m-d'),
            nifCliente: $nif,
            paisCliente: $pais,
            nomeCliente: (string) $nome,
            impostoCentimos: $impostoCentimos,
            liquidoCentimos: $liquidoCentimos,
            brutoCentimos: $brutoCentimos,
        );
    }
}
EOF_F3A_1
mkdir -p Modules/Faturacao/database/migrations
cat > Modules/Faturacao/database/migrations/2026_10_15_000014_add_cliente_congelado_aos_documentos.php <<'EOF_F3A_2'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABELAS = ['faturas', 'notas_credito_debito', 'recibos'];

    /**
     * Dados do cliente congelados no documento no momento da emissão (NIF,
     * nome e país — os campos que entram na assinatura). Editar o cliente
     * depois já não altera o que foi assinado, impresso ou enviado à AGT.
     * Documentos anteriores ficam com estes campos vazios e continuam a usar
     * os dados atuais do cliente.
     */
    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (Schema::hasColumn($tabela, 'cliente_nome')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->string('cliente_nif', 50)->nullable();
                $table->string('cliente_nome', 255)->nullable();
                $table->string('cliente_pais', 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasColumn($tabela, 'cliente_nome')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn(['cliente_nif', 'cliente_nome', 'cliente_pais']);
            });
        }
    }
};
EOF_F3A_2
mkdir -p Modules/Faturacao/tests/Unit
cat > Modules/Faturacao/tests/Unit/QrCodeAgtTest.php <<'EOF_F3A_3'
<?php

namespace Modules\Faturacao\Tests\Unit;

use Modules\Faturacao\Support\QrCodeAgt;
use PHPUnit\Framework\TestCase;

class QrCodeAgtTest extends TestCase
{
    public function test_url_segue_a_especificacao_da_agt_com_espacos_codificados(): void
    {
        $this->assertSame(
            'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=5001636863&document=FT%20FT6325S2C/10006',
            QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006'),
        );
    }

    public function test_png_tem_350_por_350_pixeis(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Extensão GD não disponível.');
        }

        $png = QrCodeAgt::png(QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006'));
        [$largura, $altura, $tipo] = getimagesizefromstring((string) $png);

        $this->assertSame([350, 350, IMAGETYPE_PNG], [$largura, $altura, $tipo]);
    }

    public function test_svg_e_data_uri(): void
    {
        $url = QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006');

        $this->assertStringStartsWith('<svg ', QrCodeAgt::svg($url));
        $this->assertMatchesRegularExpression('#^data:image/(png|svg\+xml);base64,#', QrCodeAgt::dataUri($url));
    }
}
EOF_F3A_3
mkdir -p Modules/Faturacao/tests/Feature
cat > Modules/Faturacao/tests/Feature/ClienteCongeladoTest.php <<'EOF_F3A_4'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\QrCodeAgt;
use Tests\TestCase;

class ClienteCongeladoTest extends TestCase
{
    use RefreshDatabase;

    protected string $publica;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/congelado-priv-'.uniqid().'.pem';
        $this->publica = sys_get_temp_dir().'/congelado-pub-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        file_put_contents($this->publica, openssl_pkey_get_details($recurso)['key']);

        config([
            'faturacao.chave_privada_path' => $privada,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/congelado-sem-chaves-'.uniqid(),
        ]);

        $this->beforeApplicationDestroyed(function () use ($privada) {
            @unlink($privada);
            @unlink($this->publica);
        });
    }

    protected function emitir(string $nif, ?string $nifCliente = null): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Original', 'nif' => $nifCliente]);

        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return [$empresa, $cliente, $servico->emitir($servico->criarRascunho($empresa, $cliente, $linhas))];
    }

    public function test_emissao_congela_nif_nome_e_pais_do_cliente(): void
    {
        [, , $fatura] = $this->emitir('330000001', '987654321');

        $this->assertSame('Cliente Original', $fatura->cliente_nome);
        $this->assertSame('987654321', $fatura->cliente_nif);
        $this->assertSame('AO', $fatura->cliente_pais);
    }

    public function test_editar_o_cliente_depois_nao_altera_o_que_foi_assinado(): void
    {
        [, $cliente, $fatura] = $this->emitir('330000002', '987654321');
        $original = Jws::payload($fatura->hash);

        Cliente::withoutGlobalScopes()->whereKey($cliente->id)->update(['nome' => 'Nome Alterado Lda', 'nif' => '111111111']);
        $fatura = Fatura::withoutGlobalScopes()->findOrFail($fatura->id);

        $this->assertSame($original, $fatura->construirStringParaAssinar());
        $this->assertTrue(app(AssinaturaFiscalService::class)->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $this->publica));

        $assinado = json_decode((string) $original, true);
        $this->assertSame('Cliente Original', $assinado['companyName']);
        $this->assertSame('987654321', $assinado['customerTaxID']);
    }

    public function test_recibo_tambem_congela_o_cliente(): void
    {
        [$empresa, $cliente, $fatura] = $this->emitir('330000003');

        $recibo = app(ReciboService::class)->emitir($empresa->id, $cliente->id, '500.00', 'dinheiro', $fatura->id);

        $this->assertSame('Cliente Original', $recibo->cliente_nome);
        $this->assertSame('AO', $recibo->cliente_pais);
    }

    public function test_url_do_qr_vem_do_documento_assinado(): void
    {
        [, , $fatura] = $this->emitir('330000004');

        $this->assertSame(QrCodeAgt::url('330000004', $fatura->numero_documento), $fatura->urlQrAgt());
    }

    public function test_rascunho_nao_tem_url_de_qr(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja', 'nif' => '330000005']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Original']);
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($empresa, $cliente, [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]]);

        $this->assertNull($rascunho->urlQrAgt());
    }
}
EOF_F3A_4
python3 - <<'EOF_PATCH_3A'
import re, sys, pathlib

M = pathlib.Path('Modules/Faturacao')

def ler(p): return p.read_text(encoding='utf-8')
def gravar(p, t): p.write_text(t, encoding='utf-8')

def falhar(msg):
    sys.exit(f'ERRO: {msg} Nada foi commitado; vê as instruções acima para desfazer.')

# ---------- Serviço de assinatura: congelar o cliente na emissão ----------
f = M / 'app/Services/AssinaturaFiscalService.php'
t = ler(f)
if 'congelarCliente' not in t:
    t, n = re.subn(r"(        \$documento->prepararParaEmissao\([^;]*\);\n)",
                   r"\1\n        // Congela NIF, nome e país do cliente no documento antes de construir o objecto assinado.\n        if (method_exists($documento, 'congelarCliente')) {\n            $documento->congelarCliente();\n        }\n", t, count=1)
    if n != 1: falhar('chamada a prepararParaEmissao não encontrada em AssinaturaFiscalService.')
    gravar(f, t)

# ---------- Vista da fatura: dados congelados + QR Code ----------
f = M / 'resources/views/faturas/mostrar.blade.php'
t = ler(f)
if 'urlQrAgt' not in t:
    antigo = """<p class="text-sm text-slate-500">{{ $fatura->cliente->nome }} @if($fatura->cliente->nif) — NIF {{ $fatura->cliente->nif }} @endif</p>"""
    novo = """<p class="text-sm text-slate-500">
                    @php
                        $clienteNome = $fatura->cliente_nome ?? $fatura->cliente->nome;
                        $clienteNif = $fatura->cliente_nome !== null ? $fatura->cliente_nif : $fatura->cliente->nif;
                    @endphp
                    {{ $clienteNome }} @if($clienteNif) — NIF {{ $clienteNif }} @endif
                </p>"""
    if antigo not in t: falhar('linha do cliente não encontrada em mostrar.blade.php.')
    t = t.replace(antigo, novo, 1)

    ancora = "\n        @if ($fatura->estado === 'rascunho')"
    if ancora not in t: falhar('bloco do rascunho não encontrado em mostrar.blade.php.')
    qr = """
        @if (config('faturacao.agt.ativo') && $fatura->urlQrAgt())
            <div class="mt-6 flex items-center gap-4 border-t border-slate-200 pt-4">
                <img src="{{ \\Modules\\Faturacao\\Support\\QrCodeAgt::dataUri($fatura->urlQrAgt()) }}" alt="QR Code da AGT" width="175" height="175">
                <p class="text-xs text-slate-500">Consulte este documento no portal da AGT lendo o QR Code.</p>
            </div>
        @endif
"""
    t = t.replace(ancora, qr + ancora, 1)
    gravar(f, t)

# ---------- README ----------
f = M / 'README.md'
t = ler(f)
if 'Cliente congelado e QR Code' not in t:
    t += """

## Cliente congelado e QR Code (Fase 3a)

- **Cliente congelado:** na emissão, o NIF, o nome e o país do cliente ficam gravados no documento
  (`cliente_nif`, `cliente_nome`, `cliente_pais`). Editar o cliente depois já não altera o que foi
  assinado, mostrado ou enviado à AGT. Documentos anteriores (sem estes campos) usam os dados atuais.
- **QR Code:** com `FATURACAO_AGT_ATIVO=true`, a fatura mostra o QR Code da AGT
  (`.../consultar-fe?emissor={NIF}&document={documentNo}`, espaços => `%20`), PNG 350x350
  (SVG se a extensão GD faltar). A AGT indica "versão 4 (33x33)", mas o URL tem ~120 bytes e a
  versão 4 só comporta 62 com correção M: usa-se a menor versão que o comporta. O logótipo da AGT
  no centro (opcional, <20%) não está incluído. Requer `bacon/qr-code` (composer).

**Bloqueado (SAF-T):** o exportador SAF-T grava o JWS completo (~750 caracteres) no campo `Hash`.
O esquema SAF-T(AO) público (SAFTAO1.01_01, 2020) limita esse campo a 172 caracteres. Falta a
especificação atual da AGT para o SAF-T com faturação eletrónica; até a haver, não uses o SAF-T
exportado para submissões.
"""
    gravar(f, t)

print('Fase 3a: patches aplicados com sucesso.')

EOF_PATCH_3A

echo "== Sintaxe PHP =="
for f in $(git status --porcelain | awk '{print $2}' | grep '\.php$'); do php -l "$f" >/dev/null; done

echo "== Estilo (Pint) =="
vendor/bin/pint Modules/Faturacao

echo "== Migração (base de dados local) =="
php artisan migrate --force || echo "AVISO: migrate falhou na BD local; os testes usam SQLite em memória e continuam."

echo "== Testes (todos) =="
php artisan test

echo "== Commit e push direto para main =="
git add -A -- Modules/Faturacao composer.json composer.lock
git commit -m "feat(faturacao): cliente congelado no documento e QR Code da AGT" \
  -m "Fase 3a: NIF, nome e país do cliente gravados no documento na emissão (editar o cliente já não altera o assinado); QR Code AGT (consultar-fe) em PNG 350x350 com fallback SVG, mostrado na fatura com a AGT ativa; bacon/qr-code."
git push origin main
echo "FASE 3a CONCLUÍDA e enviada para main."
)
