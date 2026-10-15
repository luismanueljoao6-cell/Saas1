(
# Fase 2a — Facturação Electrónica AGT: séries autorizadas, cliente HTTP e assinatura do software.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[ -d Modules/Faturacao ] || { echo "ERRO: corre isto na raiz do projeto Saas1."; exit 1; }
git checkout main
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERRO: tens alterações por commitar. Faz commit ou 'git stash' primeiro."; exit 1
fi
git pull --ff-only origin main
[ -f Modules/Faturacao/app/Support/Jws.php ] || { echo "ERRO: a Fase 1 (assinatura JWS) ainda não está na main. Corre primeiro o comando da Fase 1."; exit 1; }
mkdir -p Modules/Faturacao/app/Exceptions
cat > Modules/Faturacao/app/Exceptions/AgtException.php <<'EOF_F2A_0'
<?php

namespace Modules\Faturacao\Exceptions;

use Exception;

/**
 * Erros na comunicação com a AGT (Facturação Electrónica) e na gestão das
 * séries autorizadas. `$erros` normaliza a errorList devolvida pela AGT.
 */
class AgtException extends Exception
{
    /** @param  array<int, array{codigo: string, descricao: string}>  $erros */
    public function __construct(string $message, public readonly array $erros = [])
    {
        parent::__construct($message);
    }

    public static function semCredenciais(): self
    {
        return new self('Credenciais da AGT em falta: define FATURACAO_AGT_UTILIZADOR e FATURACAO_AGT_PALAVRA_PASSE (pedido por e-mail a produtores.dfe.dcrr.agt@minfin.gov.ao).');
    }

    public static function configuracaoInvalida(string $motivo): self
    {
        return new self('Configuração AGT inválida: '.$motivo);
    }

    public static function transporte(string $motivo): self
    {
        return new self('Falha de comunicação com a AGT: '.$motivo);
    }

    public static function respostaInesperada(int $http): self
    {
        return new self("Resposta inesperada da AGT (HTTP {$http}).");
    }

    /** @param  array<int, array{codigo: string, descricao: string}>  $erros */
    public static function rejeitado(array $erros): self
    {
        $texto = implode('; ', array_map(fn (array $e) => "{$e['codigo']}: {$e['descricao']}", $erros));

        return new self('A AGT rejeitou o pedido — '.$texto, $erros);
    }

    public static function serieIndisponivel(string $tipoDocumento, int $ano): self
    {
        return new self("Não existe série autorizada pela AGT para {$tipoDocumento}/{$ano}. Pede-a com: php artisan faturacao:agt-solicitar-serie <empresa> {$tipoDocumento}");
    }

    public static function serieEsgotada(string $codigo, int $ultimoAutorizado): self
    {
        return new self("A série AGT {$codigo} esgotou a numeração autorizada (até {$ultimoAutorizado}). Pede uma extensão da série à AGT.");
    }
}
EOF_F2A_0
mkdir -p Modules/Faturacao/database/migrations
cat > Modules/Faturacao/database/migrations/2026_10_13_000012_add_campos_agt_to_series_table.php <<'EOF_F2A_1'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séries autorizadas pela AGT (serviço "Solicitar Criação de Série"):
     * código atribuído pela AGT, estabelecimento, indicador de contingência e
     * intervalo de numeração autorizado. Fase 2a da adequação à AGT.
     */
    public function up(): void
    {
        if (Schema::hasColumn('series', 'agt_codigo')) {
            return;
        }

        Schema::table('series', function (Blueprint $table) {
            $table->string('agt_codigo', 60)->nullable()->after('prefixo');
            $table->string('estabelecimento', 200)->nullable()->after('agt_codigo');
            $table->string('contingencia', 1)->default('N')->after('estabelecimento');
            $table->unsignedBigInteger('quantidade_autorizada')->nullable()->after('ultimo_numero');
            $table->unsignedBigInteger('ultimo_numero_autorizado')->nullable()->after('quantidade_autorizada');
            $table->timestamp('agt_criada_em')->nullable()->after('ultimo_numero_autorizado');

            // O código de série é único por contribuinte (regra E31 da AGT).
            $table->unique(['empresa_id', 'agt_codigo']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('series', 'agt_codigo')) {
            return;
        }

        Schema::table('series', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'agt_codigo']);
            $table->dropColumn([
                'agt_codigo', 'estabelecimento', 'contingencia',
                'quantidade_autorizada', 'ultimo_numero_autorizado', 'agt_criada_em',
            ]);
        });
    }
};
EOF_F2A_1
mkdir -p Modules/Faturacao/app/Services
cat > Modules/Faturacao/app/Services/AgtClient.php <<'EOF_F2A_2'
<?php

namespace Modules\Faturacao\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Faturacao\Exceptions\AgtException;

/**
 * Cliente HTTP da API de Facturação Electrónica da AGT: Basic Authentication
 * com as credenciais emitidas ao produtor do software, corpo JSON já
 * canónico e assinado por quem chama.
 */
class AgtClient
{
    /**
     * @return array{http: int, corpo: array<string, mixed>}
     */
    public function enviar(string $servico, string $corpoJson): array
    {
        $utilizador = (string) config('faturacao.agt.utilizador');
        $palavraPasse = (string) config('faturacao.agt.palavra_passe');

        if ($utilizador === '' || $palavraPasse === '') {
            throw AgtException::semCredenciais();
        }

        $url = $this->url($servico);

        try {
            $resposta = Http::withBasicAuth($utilizador, $palavraPasse)
                ->acceptJson()
                ->timeout((int) config('faturacao.agt.timeout', 20))
                ->withBody($corpoJson, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            throw AgtException::transporte($e->getMessage());
        }

        $corpo = $resposta->json();

        return ['http' => $resposta->status(), 'corpo' => is_array($corpo) ? $corpo : []];
    }

    /**
     * @return array{http: int, corpo: array<string, mixed>}
     */
    public function solicitarSerie(string $corpoJson): array
    {
        return $this->enviar('solicitar_serie', $corpoJson);
    }

    /**
     * Normaliza a errorList da AGT (objectos {idError, descriptionError} ou
     * textos soltos; entradas vazias são ignoradas).
     *
     * @return array<int, array{codigo: string, descricao: string}>
     */
    public static function normalizarErros(mixed $lista): array
    {
        $erros = [];

        foreach (is_array($lista) ? $lista : [] as $item) {
            if (is_array($item)) {
                $codigo = (string) ($item['idError'] ?? $item['errorCode'] ?? 'E99');
                $descricao = (string) ($item['descriptionError'] ?? $item['errorDescription'] ?? '');
                $erros[] = ['codigo' => $codigo, 'descricao' => $descricao];
            } elseif (is_string($item) && trim($item) !== '') {
                $erros[] = ['codigo' => 'E99', 'descricao' => trim($item)];
            }
        }

        return $erros;
    }

    protected function url(string $servico): string
    {
        $ambiente = (string) config('faturacao.agt.ambiente', 'homologacao');

        if (! in_array($ambiente, ['homologacao', 'producao'], true)) {
            throw AgtException::configuracaoInvalida("ambiente '{$ambiente}' (usa homologacao ou producao).");
        }

        if ($ambiente === 'producao' && (string) config('faturacao.saft_numero_certificado', '0') === '0') {
            throw AgtException::configuracaoInvalida('produção exige o número de certificação do software (FATURACAO_SAFT_NUMERO_CERTIFICADO).');
        }

        $base = rtrim((string) config("faturacao.agt.urls.{$ambiente}"), '/');
        $caminho = (string) config("faturacao.agt.caminhos.{$servico}");

        if ($base === '' || $caminho === '') {
            throw AgtException::configuracaoInvalida("endereço do serviço '{$servico}' não configurado.");
        }

        return $base.'/'.ltrim($caminho, '/');
    }
}
EOF_F2A_2
mkdir -p Modules/Faturacao/app/Services
cat > Modules/Faturacao/app/Services/AgtAssinaturaService.php <<'EOF_F2A_3'
<?php

namespace Modules\Faturacao\Services;

use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Support\Jws;
use OpenSSLAsymmetricKey;

/**
 * Assinaturas JWS dos pedidos à AGT: jwsSoftwareSignature (chave do SOFTWARE,
 * gerada pelo produtor) e jwsSignature das requisições (chave do EMISSOR,
 * emitida pela AGT — reutiliza AssinaturaFiscalService, que resolve a chave
 * por empresa).
 */
class AgtAssinaturaService
{
    protected ?OpenSSLAsymmetricKey $chaveSoftware = null;

    public function __construct(protected AssinaturaFiscalService $assinaturaFiscal) {}

    /**
     * Bloco softwareInfo de todos os pedidos. Assina-se o objecto
     * softwareInfoDetail inteiro, em JSON canónico.
     *
     * @return array{softwareInfoDetail: array<string, mixed>, jwsSoftwareSignature: string}
     */
    public function softwareInfo(): array
    {
        $detalhe = [
            'productId' => (string) config('faturacao.agt.software.product_id'),
            'productVersion' => (string) config('faturacao.agt.software.product_version'),
            'softwareValidationNumber' => (string) config('faturacao.saft_numero_certificado'),
        ];

        // A tabela da AGT lista signatureVersion, mas os exemplos oficiais não o
        // incluem: só entra se for configurado (confirmar na homologação).
        $versao = config('faturacao.agt.software.signature_version');

        if ($versao !== null && $versao !== '') {
            $detalhe['signatureVersion'] = (int) $versao;
        }

        return [
            'softwareInfoDetail' => $detalhe,
            'jwsSoftwareSignature' => Jws::assinar(Jws::canonico($detalhe), $this->carregarChaveSoftware()),
        ];
    }

    /**
     * jwsSignature de uma requisição, com a chave do emissor (empresa).
     *
     * @param  array<string, mixed>  $objeto  objecto exacto a assinar (ordem das chaves incluída)
     */
    public function assinarRequisicao(int $empresaId, array $objeto): string
    {
        return $this->assinaturaFiscal->assinar(Jws::canonico($objeto), $empresaId);
    }

    protected function carregarChaveSoftware(): OpenSSLAsymmetricKey
    {
        if ($this->chaveSoftware !== null) {
            return $this->chaveSoftware;
        }

        $caminho = (string) config('faturacao.agt.software.chave_privada_path');

        if (! is_file($caminho)) {
            throw AssinaturaFiscalException::chavePrivadaNaoEncontrada($caminho);
        }

        $chave = openssl_pkey_get_private((string) file_get_contents($caminho));

        if (! $chave) {
            throw AssinaturaFiscalException::chavePrivadaInvalida();
        }

        $detalhes = openssl_pkey_get_details($chave) ?: [];
        $minimo = (int) config('faturacao.chave_minima_bits', 2048);

        if (($detalhes['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw AssinaturaFiscalException::chavePrivadaInvalida();
        }

        if ((int) ($detalhes['bits'] ?? 0) < $minimo) {
            throw AssinaturaFiscalException::chaveFraca((int) ($detalhes['bits'] ?? 0), $minimo);
        }

        return $this->chaveSoftware = $chave;
    }
}
EOF_F2A_3
mkdir -p Modules/Faturacao/app/Services
cat > Modules/Faturacao/app/Services/AgtSerieService.php <<'EOF_F2A_4'
<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Support\Jws;

/**
 * Pede à AGT a criação (ou extensão) de uma série de numeração de facturas
 * electrónicas e guarda o código e o intervalo autorizados. O documentNo
 * passa a ser "{tipo} {seriesCode}/{n}", dentro do intervalo autorizado.
 */
class AgtSerieService
{
    public function __construct(
        protected AgtClient $cliente,
        protected AgtAssinaturaService $assinaturas,
        protected TenantManager $tenantManager,
    ) {}

    public function solicitar(
        Empresa $empresa,
        string $tipoDocumento,
        ?int $ano = null,
        ?string $estabelecimento = null,
        string $contingencia = 'N',
    ): Serie {
        if (! array_key_exists($tipoDocumento, (array) config('faturacao.tipos_documento'))) {
            throw new InvalidArgumentException("Tipo de documento '{$tipoDocumento}' não suportado.");
        }

        if (! in_array($contingencia, ['N', 'C'], true)) {
            throw new InvalidArgumentException("Indicador de contingência '{$contingencia}' inválido (N ou C).");
        }

        $nif = trim((string) $empresa->nif);

        if ($nif === '') {
            throw new InvalidArgumentException('A empresa não tem NIF configurado.');
        }

        $ano ??= now()->year;
        $estabelecimento = trim((string) $estabelecimento) !== ''
            ? trim((string) $estabelecimento)
            : (string) config('faturacao.agt.estabelecimento_padrao', 'SEDE');

        // Objecto assinado em jwsSignature, na ordem indicada pela AGT.
        $assinado = [
            'taxRegistrationNumber' => $nif,
            'seriesYear' => $ano,
            'documentType' => $tipoDocumento,
            'establishmentNumber' => $estabelecimento,
            'seriesContingencyIndicator' => $contingencia,
        ];

        $corpo = Jws::canonico([
            'schemaVersion' => '2.0',
            'submissionUUID' => (string) Str::uuid(),
            'taxRegistrationNumber' => $nif,
            'submissionTimeStamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->assinaturas->softwareInfo(),
            'seriesYear' => $ano,
            'documentType' => $tipoDocumento,
            'establishmentNumber' => $estabelecimento,
            'jwsSignature' => $this->assinaturas->assinarRequisicao((int) $empresa->id, $assinado),
            'seriesContingencyIndicator' => $contingencia,
        ]);

        $resposta = $this->cliente->solicitarSerie($corpo);
        $resultado = $resposta['corpo']['seriesFEResult'] ?? null;

        if ($resposta['http'] !== 200 || ! is_array($resultado) || empty($resultado['seriesCode'])) {
            $erros = AgtClient::normalizarErros($resposta['corpo']['errorList'] ?? []);

            throw $erros !== []
                ? AgtException::rejeitado($erros)
                : AgtException::respostaInesperada($resposta['http']);
        }

        return $this->guardar($empresa, $tipoDocumento, $ano, $estabelecimento, $contingencia, $resultado);
    }

    /** @param  array<string, mixed>  $resultado  seriesFEResult */
    protected function guardar(Empresa $empresa, string $tipoDocumento, int $ano, string $estabelecimento, string $contingencia, array $resultado): Serie
    {
        $codigo = (string) $resultado['seriesCode'];
        $primeiro = $this->numeroDe($resultado['firstDocumentNo'] ?? null);
        $ultimo = $this->numeroDe($resultado['lastDocumentNo'] ?? null);
        $quantidade = $this->numeroDe($resultado['authorizedQuantity'] ?? null, obrigatorio: false) ?? max(0, $ultimo - $primeiro + 1);

        return $this->tenantManager->semTenant(fn () => DB::transaction(function () use ($empresa, $tipoDocumento, $ano, $estabelecimento, $contingencia, $codigo, $primeiro, $ultimo, $quantidade) {
            $serie = Serie::query()
                ->where('empresa_id', $empresa->id)
                ->where('agt_codigo', $codigo)
                ->lockForUpdate()
                ->first();

            if ($serie) {
                // Extensão de uma série existente: a numeração continua, só cresce o limite.
                $serie->update([
                    'quantidade_autorizada' => $quantidade,
                    'ultimo_numero_autorizado' => max((int) $serie->ultimo_numero_autorizado, $ultimo),
                ]);

                return $serie;
            }

            return Serie::create([
                'empresa_id' => $empresa->id,
                'tipo_documento' => $tipoDocumento,
                'ano' => $ano,
                'prefixo' => $codigo,
                'agt_codigo' => $codigo,
                'estabelecimento' => $estabelecimento,
                'contingencia' => $contingencia,
                'quantidade_autorizada' => $quantidade,
                // A primeira factura emitida terá o número "primeiro".
                'ultimo_numero' => max(0, $primeiro - 1),
                'ultimo_numero_autorizado' => $ultimo,
                'agt_criada_em' => now(),
            ]);
        }));
    }

    /** Aceita "1000" ou um identificador completo terminado em número ("FT X/1000"). */
    protected function numeroDe(mixed $valor, bool $obrigatorio = true): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_string($valor) && preg_match('/(\d+)$/', trim($valor), $m) === 1) {
            return (int) $m[1];
        }

        if ($obrigatorio) {
            throw AgtException::configuracaoInvalida('a AGT devolveu um intervalo de numeração inválido para a série.');
        }

        return null;
    }
}
EOF_F2A_4
mkdir -p Modules/Faturacao/app/Console
cat > Modules/Faturacao/app/Console/SolicitarSerieAgtCommand.php <<'EOF_F2A_5'
<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtSerieService;
use Throwable;

/**
 * Pede à AGT uma série de numeração (ou a sua extensão) para uma empresa.
 */
class SolicitarSerieAgtCommand extends Command
{
    protected $signature = 'faturacao:agt-solicitar-serie
        {empresa : ID da empresa}
        {tipo : Tipo de documento (FT, NC, ND, RC)}
        {--ano= : Ano da série (por omissão, o ano atual)}
        {--estabelecimento= : Código do estabelecimento (por omissão, SEDE)}
        {--contingencia=N : N (regime normal) ou C (contingência)}';

    protected $description = 'Solicita à AGT uma série de numeração de facturas electrónicas e guarda o intervalo autorizado';

    public function handle(AgtSerieService $servico): int
    {
        $empresa = Empresa::query()->find((int) $this->argument('empresa'));

        if (! $empresa) {
            $this->error('Empresa inexistente.');

            return self::FAILURE;
        }

        try {
            $serie = $servico->solicitar(
                $empresa,
                strtoupper((string) $this->argument('tipo')),
                $this->option('ano') !== null ? (int) $this->option('ano') : null,
                $this->option('estabelecimento') ?: null,
                strtoupper((string) $this->option('contingencia')),
            );
        } catch (AgtException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Falha ao solicitar a série: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Série {$serie->agt_codigo} ({$serie->tipo_documento}/{$serie->ano}) autorizada até ao nº {$serie->ultimo_numero_autorizado}.");

        return self::SUCCESS;
    }
}
EOF_F2A_5
mkdir -p Modules/Faturacao/tests/Feature
cat > Modules/Faturacao/tests/Feature/AgtSerieTest.php <<'EOF_F2A_6'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtClient;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

class AgtSerieTest extends TestCase
{
    use RefreshDatabase;

    protected string $publicaEmpresa;

    protected string $publicaSoftware;

    protected function setUp(): void
    {
        parent::setUp();

        [$privadaEmpresa, $this->publicaEmpresa] = $this->gerarPar();
        [$privadaSoftware, $this->publicaSoftware] = $this->gerarPar();

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/agt-sem-chaves-'.uniqid(),
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.product_id' => 'Saas1 Teste',
            'faturacao.agt.software.product_version' => '1.0.0',
            'faturacao.agt.software.signature_version' => null,
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);
    }

    /** @return array{0: string, 1: string} caminhos [privada, publica] */
    protected function gerarPar(): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/agt-priv-'.uniqid().'.pem';
        $publica = sys_get_temp_dir().'/agt-pub-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        file_put_contents($publica, openssl_pkey_get_details($recurso)['key']);

        $this->beforeApplicationDestroyed(function () use ($privada, $publica) {
            @unlink($privada);
            @unlink($publica);
        });

        return [$privada, $publica];
    }

    protected function empresa(): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
    }

    protected function respostaSerie(string $ultimo = '1000'): void
    {
        Http::fake(['*' => Http::response([
            'resultCode' => 1,
            'errorList' => [''],
            'seriesFEResult' => [
                'seriesCode' => 'FT6325S2C',
                'authorizedQuantity' => $ultimo,
                'firstDocumentNo' => '1',
                'lastDocumentNo' => $ultimo,
            ],
        ], 200)]);
    }

    public function test_solicitar_serie_envia_pedido_assinado_e_guarda_o_intervalo_autorizado(): void
    {
        $this->respostaSerie();
        $serie = app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);

        $this->assertSame('FT6325S2C', $serie->agt_codigo);
        $this->assertSame('SEDE', $serie->estabelecimento);
        $this->assertSame(0, $serie->ultimo_numero);
        $this->assertSame(1000, $serie->ultimo_numero_autorizado);

        [$pedido] = Http::recorded()[0];
        $dados = $pedido->data();

        $this->assertSame('https://sifphml.minfin.gov.ao/sigt/fe/v1/solicitarSerie', $pedido->url());
        $this->assertSame('Basic '.base64_encode('produtor:s3nh@'), $pedido->header('Authorization')[0]);
        $this->assertSame('2.0', $dados['schemaVersion']);
        $this->assertSame('5001636863', $dados['taxRegistrationNumber']);
        $this->assertSame('FT', $dados['documentType']);
        $this->assertSame('N', $dados['seriesContingencyIndicator']);

        // jwsSignature (chave do emissor): objecto exacto indicado pela AGT.
        $this->assertSame(
            '{"taxRegistrationNumber":"5001636863","seriesYear":2026,"documentType":"FT","establishmentNumber":"SEDE","seriesContingencyIndicator":"N"}',
            Jws::payload($dados['jwsSignature']),
        );
        $this->assertTrue(Jws::verificar($dados['jwsSignature'], openssl_pkey_get_public((string) file_get_contents($this->publicaEmpresa))));

        // jwsSoftwareSignature (chave do software): softwareInfoDetail inteiro.
        $assinaturaSoftware = $dados['softwareInfo']['jwsSoftwareSignature'];
        $this->assertSame('{"productId":"Saas1 Teste","productVersion":"1.0.0","softwareValidationNumber":"C_134"}', Jws::payload($assinaturaSoftware));
        $this->assertTrue(Jws::verificar($assinaturaSoftware, openssl_pkey_get_public((string) file_get_contents($this->publicaSoftware))));
    }

    public function test_numeracao_com_serie_agt_usa_o_codigo_da_serie_e_respeita_o_limite(): void
    {
        config(['faturacao.agt.ativo' => true]);
        $empresa = $this->empresa();
        $this->respostaSerie('2');
        app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);

        $numeracao = app(NumeracaoService::class);

        $this->assertSame('FT FT6325S2C/1', $numeracao->proximoNumero($empresa->id, 'FT')['numero_documento']);
        $this->assertSame('FT FT6325S2C/2', $numeracao->proximoNumero($empresa->id, 'FT')['numero_documento']);

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('esgotou');

        $numeracao->proximoNumero($empresa->id, 'FT');
    }

    public function test_extensao_de_serie_aumenta_o_limite_sem_reiniciar_a_numeracao(): void
    {
        config(['faturacao.agt.ativo' => true]);
        $empresa = $this->empresa();
        $this->respostaSerie('1');
        app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);
        app(NumeracaoService::class)->proximoNumero($empresa->id, 'FT');

        $this->respostaSerie('5');
        $serie = app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);

        $this->assertSame(1, $serie->ultimo_numero);
        $this->assertSame(5, $serie->ultimo_numero_autorizado);
        $this->assertSame('FT FT6325S2C/2', app(NumeracaoService::class)->proximoNumero($empresa->id, 'FT')['numero_documento']);
    }

    public function test_com_agt_ativa_nao_se_inventa_serie_local(): void
    {
        config(['faturacao.agt.ativo' => true]);
        $empresa = $this->empresa();

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('faturacao:agt-solicitar-serie');

        app(NumeracaoService::class)->obterOuCriarSerie($empresa->id, 'FT');
    }

    public function test_rejeicao_da_agt_devolve_os_codigos_de_erro(): void
    {
        Http::fake(['*' => Http::response(['errorList' => [['idError' => 'E31', 'descriptionError' => 'Código de série já em utilização']]], 400)]);

        try {
            app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);
        } catch (AgtException $e) {
            $this->assertSame('E31', $e->erros[0]['codigo']);
            $this->assertStringContainsString('E31', $e->getMessage());

            return;
        }

        $this->fail('Era esperada uma AgtException.');
    }

    public function test_sem_credenciais_nada_e_enviado(): void
    {
        Http::fake();
        config(['faturacao.agt.utilizador' => '', 'faturacao.agt.palavra_passe' => '']);

        try {
            app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);
            $this->fail('Era esperada uma AgtException.');
        } catch (AgtException $e) {
            $this->assertStringContainsString('FATURACAO_AGT_UTILIZADOR', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_producao_exige_numero_de_certificacao_do_software(): void
    {
        Http::fake();
        config(['faturacao.agt.ambiente' => 'producao', 'faturacao.saft_numero_certificado' => '0']);

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('certificação');

        try {
            app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_normalizar_erros_aceita_objectos_e_textos_e_ignora_vazios(): void
    {
        $erros = AgtClient::normalizarErros([
            '',
            'falha solta',
            ['idError' => 'E09', 'descriptionError' => 'Factura já consta no repositório'],
        ]);

        $this->assertSame([
            ['codigo' => 'E99', 'descricao' => 'falha solta'],
            ['codigo' => 'E09', 'descricao' => 'Factura já consta no repositório'],
        ], $erros);
    }
}
EOF_F2A_6
python3 - <<'EOF_PATCH_2A'
import sys, pathlib

M = pathlib.Path('Modules/Faturacao')

def ler(p): return p.read_text(encoding='utf-8')
def gravar(p, t): p.write_text(t, encoding='utf-8')

def substituir(texto, antigo, novo, ficheiro):
    if novo in texto and antigo not in texto:
        return texto  # já aplicado
    if antigo not in texto:
        sys.exit(f'ERRO: bloco esperado não encontrado em {ficheiro}. Nada mais foi alterado por este passo.')
    return texto.replace(antigo, novo, 1)

# ---------- Serie ----------
f = M / 'app/Models/Serie.php'
t = ler(f)
if 'agt_codigo' not in t:
    t = substituir(t, "        'ultimo_numero',\n    ];", "        'ultimo_numero',\n        'agt_codigo',\n        'estabelecimento',\n        'contingencia',\n        'quantidade_autorizada',\n        'ultimo_numero_autorizado',\n        'agt_criada_em',\n    ];", 'Serie.php')
    t = substituir(t, "            'ultimo_numero' => 'integer',\n        ];", "            'ultimo_numero' => 'integer',\n            'quantidade_autorizada' => 'integer',\n            'ultimo_numero_autorizado' => 'integer',\n            'agt_criada_em' => 'datetime',\n        ];", 'Serie.php')
    t = substituir(t, 'return "{$this->tipo_documento} {$this->prefixo}{$this->ano}";', 'return $this->agt_codigo !== null\n            ? "{$this->tipo_documento} {$this->agt_codigo}"\n            : "{$this->tipo_documento} {$this->prefixo}{$this->ano}";', 'Serie.php')
    gravar(f, t)

# ---------- NumeracaoService ----------
f = M / 'app/Services/NumeracaoService.php'
t = ler(f)
if 'serieAgtAtual' not in t:
    t = substituir(t, "use Modules\\Faturacao\\Exceptions\\CadeiaFiscalInterrompidaException;\n",
                   "use Modules\\Faturacao\\Exceptions\\AgtException;\nuse Modules\\Faturacao\\Exceptions\\CadeiaFiscalInterrompidaException;\n", 'NumeracaoService.php')
    t = substituir(t, "                $novoNumero = $serie->ultimo_numero + 1;\n                $serie->update(['ultimo_numero' => $novoNumero]);\n",
                   "                $novoNumero = $serie->ultimo_numero + 1;\n\n                if ($serie->agt_codigo !== null\n                    && $serie->ultimo_numero_autorizado !== null\n                    && $novoNumero > $serie->ultimo_numero_autorizado) {\n                    throw AgtException::serieEsgotada($serie->agt_codigo, (int) $serie->ultimo_numero_autorizado);\n                }\n\n                $serie->update(['ultimo_numero' => $novoNumero]);\n", 'NumeracaoService.php')
    t = substituir(t, "                    'numero_documento' => \"{$tipoDocumento} {$prefixo}{$serie->ano}/{$novoNumero}\",\n",
                   "                    'numero_documento' => $serie->agt_codigo !== null\n                        ? \"{$tipoDocumento} {$serie->agt_codigo}/{$novoNumero}\"\n                        : \"{$tipoDocumento} {$prefixo}{$serie->ano}/{$novoNumero}\",\n", 'NumeracaoService.php')
    t = substituir(t, "            $ano = now()->year;\n\n            $query = Serie::query()",
                   "            $ano = now()->year;\n\n            // Com a comunicação à AGT ativa, só se numera em séries autorizadas por ela.\n            if (config('faturacao.agt.ativo')) {\n                return $this->serieAgtAtual($empresaId, $tipoDocumento, $ano, $comLock);\n            }\n\n            $query = Serie::query()", 'NumeracaoService.php')
    t = substituir(t, "    /**\n     * Duas transações podem chegar aqui em simultâneo",
                   "    /**\n     * Série autorizada pela AGT para o ano corrente (regime normal). Já corre\n     * dentro do semTenant() de quem chamou.\n     */\n    protected function serieAgtAtual(int $empresaId, string $tipoDocumento, int $ano, bool $comLock): Serie\n    {\n        $query = Serie::query()\n            ->where('empresa_id', $empresaId)\n            ->where('tipo_documento', $tipoDocumento)\n            ->where('ano', $ano)\n            ->whereNotNull('agt_codigo')\n            ->where('contingencia', 'N')\n            ->orderByDesc('id');\n\n        $serie = $comLock ? $query->lockForUpdate()->first() : $query->first();\n\n        return $serie ?? throw AgtException::serieIndisponivel($tipoDocumento, $ano);\n    }\n\n    /**\n     * Duas transações podem chegar aqui em simultâneo", 'NumeracaoService.php')
    gravar(f, t)

# ---------- Config ----------
f = M / 'config/config.php'
t = ler(f)
if "'agt' =>" not in t:
    bloco = """
    /*
    |--------------------------------------------------------------------------
    | Comunicação com a AGT (Facturação Electrónica)
    |--------------------------------------------------------------------------
    | `ativo` desligado: o módulo numera localmente (desenvolvimento). Ligado:
    | só se numera em séries autorizadas pela AGT e os pedidos usam Basic Auth
    | com as credenciais pedidas por e-mail a produtores.dfe.dcrr.agt@minfin.gov.ao
    | (nome e NIF da empresa produtora). Os endereços e caminhos são os da
    | documentação oficial da AGT e ficam configuráveis porque a documentação de
    | homologação tem inconsistências — confirma-os na homologação.
    */
    'agt' => [
        'ativo' => (bool) env('FATURACAO_AGT_ATIVO', false),
        'ambiente' => env('FATURACAO_AGT_AMBIENTE', 'homologacao'), // homologacao | producao
        'urls' => [
            'homologacao' => env('FATURACAO_AGT_URL_HOMOLOGACAO', 'https://sifphml.minfin.gov.ao/sigt/fe/v1'),
            'producao' => env('FATURACAO_AGT_URL_PRODUCAO', 'https://sifp.minfin.gov.ao/sigt/fe/v1'),
        ],
        'caminhos' => [
            'solicitar_serie' => '/solicitarSerie',
            'registar_factura' => '/registarFactura',
            'obter_estado' => '/obterEstado',
        ],
        'utilizador' => env('FATURACAO_AGT_UTILIZADOR'),
        'palavra_passe' => env('FATURACAO_AGT_PALAVRA_PASSE'),
        'timeout' => (int) env('FATURACAO_AGT_TIMEOUT', 20),
        'estabelecimento_padrao' => env('FATURACAO_AGT_ESTABELECIMENTO', 'SEDE'),
        'software' => [
            'product_id' => env('FATURACAO_AGT_PRODUCT_ID', 'Saas1'),
            'product_version' => env('FATURACAO_AGT_PRODUCT_VERSION', '1.0.0'),
            // O número de certificação é o 'saft_numero_certificado' (acima).
            'signature_version' => env('FATURACAO_AGT_SIGNATURE_VERSION'),
            'chave_privada_path' => env('FATURACAO_AGT_CHAVE_SOFTWARE') ?: storage_path('app/faturacao/chave-software.pem'),
        ],
    ],

"""
    idx = t.rstrip().rfind('];')
    t = t[:idx].rstrip('\n') + '\n' + bloco + '];\n'
    gravar(f, t)

# ---------- bootstrap/app.php: registar o comando ----------
f = pathlib.Path('bootstrap/app.php')
t = ler(f)
if 'SolicitarSerieAgtCommand' not in t:
    t = substituir(t, "use Modules\\Faturacao\\Console\\VerificarCadeiaFiscalCommand;\n",
                   "use Modules\\Faturacao\\Console\\SolicitarSerieAgtCommand;\nuse Modules\\Faturacao\\Console\\VerificarCadeiaFiscalCommand;\n", 'bootstrap/app.php')
    t = substituir(t, "        VerificarCadeiaFiscalCommand::class,\n",
                   "        VerificarCadeiaFiscalCommand::class,\n        SolicitarSerieAgtCommand::class,\n", 'bootstrap/app.php')
    gravar(f, t)

# ---------- .env.example ----------
f = pathlib.Path('.env.example')
if f.exists():
    t = ler(f)
    if 'FATURACAO_AGT_ATIVO' not in t:
        t = t.rstrip('\n') + """

# --- Comunicação com a AGT (Facturação Electrónica) ---
FATURACAO_AGT_ATIVO=false
FATURACAO_AGT_AMBIENTE=homologacao
# Credenciais Basic Auth pedidas à AGT (produtores.dfe.dcrr.agt@minfin.gov.ao)
# FATURACAO_AGT_UTILIZADOR=
# FATURACAO_AGT_PALAVRA_PASSE=
FATURACAO_AGT_ESTABELECIMENTO=SEDE
# FATURACAO_AGT_PRODUCT_ID=Saas1
# FATURACAO_AGT_PRODUCT_VERSION=1.0.0
# Chave privada RSA (>= 2048 bits) do SOFTWARE, registada na AGT
# FATURACAO_AGT_CHAVE_SOFTWARE=
"""
        gravar(f, t)

# ---------- README ----------
f = M / 'README.md'
t = ler(f)
if 'Séries da AGT' not in t:
    t += """

## Séries da AGT (Fase 2a)

Com `FATURACAO_AGT_ATIVO=true` o módulo deixa de numerar localmente: só emite em
**séries autorizadas pela AGT**. O `documentNo` passa a ser `{tipo} {seriesCode}/{n}`
(ex.: `FT FT6325S2C/10006`) e a numeração pára no último número autorizado.

1. Pede as credenciais de produtor à AGT (e-mail a `produtores.dfe.dcrr.agt@minfin.gov.ao`
   com o nome e o NIF da empresa) e regista o software/chave pública do software.
2. Cada empresa adere à faturação eletrónica no Portal do Contribuinte e a sua chave
   privada vai para `storage/app/faturacao/chaves/{NIF}.pem`.
3. Pede a série: `php artisan faturacao:agt-solicitar-serie <empresa> FT`
   (repete para NC, ND e RC; repetir o pedido para uma série existente é uma extensão).

Ainda **não** feito (Fase 2b): envio `registarFactura`, consulta de estado, QR Code.
"""
    gravar(f, t)

print('Fase 2a: patches aplicados com sucesso.')

EOF_PATCH_2A

echo "== Sintaxe PHP =="
for f in $(git status --porcelain | awk '{print $2}' | grep '\.php$'); do php -l "$f" >/dev/null; done

echo "== Estilo (Pint) =="
vendor/bin/pint Modules/Faturacao bootstrap/app.php

echo "== Migração (base de dados local) =="
php artisan migrate --force || echo "AVISO: migrate falhou na BD local; os testes usam SQLite em memória e continuam."

echo "== Testes (todos) =="
php artisan test

echo "== Commit e push direto para main =="
git add -A
git commit -m "feat(faturacao): séries autorizadas pela AGT, cliente HTTP e assinatura do software" \
  -m "Fase 2a: pedido de séries (solicitarSerie) com jwsSignature do emissor e jwsSoftwareSignature, documentNo {tipo} {seriesCode}/{n} limitado ao intervalo autorizado, Basic Auth, comando faturacao:agt-solicitar-serie. Desligado por omissão (FATURACAO_AGT_ATIVO=false)."
git push origin main
echo "FASE 2a CONCLUÍDA e enviada para main."
)
