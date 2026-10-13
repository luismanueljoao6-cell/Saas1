#!/bin/bash

echo "Iniciando processo..."
php -v
git status
echo "Tudo pronto!"


(
# Fase 1 — Facturação Electrónica AGT: assinatura JWS RS256, payload oficial, IVA por excesso.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[ -d Modules/Faturacao ] || { echo "ERRO: corre isto na raiz do projeto Saas1."; exit 1; }
git checkout main
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERRO: tens alterações por commitar. Faz commit ou 'git stash' primeiro."; exit 1
fi
git pull --ff-only origin main
mkdir -p Modules/Faturacao/app/Support
cat > Modules/Faturacao/app/Support/NumeroJson.php <<'EOF_FASE1_0'
<?php

namespace Modules\Faturacao\Support;

/**
 * Número já formatado para JSON canónico (sem aspas, sem zeros à direita
 * desnecessários). Existe para nunca passar valores monetários por float.
 */
final class NumeroJson
{
    private function __construct(public readonly string $literal) {}

    /** @param  int  $centimos  valor em cêntimos (ex.: 57000 => 570) */
    public static function deCentimos(int $centimos): self
    {
        $sinal = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);
        $inteiro = intdiv($abs, 100);
        $fracao = $abs % 100;

        if ($fracao === 0) {
            return new self($sinal.$inteiro);
        }

        return new self($sinal.$inteiro.'.'.rtrim(sprintf('%02d', $fracao), '0'));
    }
}
EOF_FASE1_0
mkdir -p Modules/Faturacao/app/Support
cat > Modules/Faturacao/app/Support/Jws.php <<'EOF_FASE1_1'
<?php

namespace Modules\Faturacao\Support;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * JSON Web Signature (compact) com RS256 (RSA + SHA-256), como exige a API de
 * Facturação Electrónica da AGT: cabeçalho {"alg":"RS256","typ":"JWT"},
 * payload = objecto JSON completo (não campos concatenados), Base64URL sem
 * padding e chave RSA com pelo menos 2048 bits.
 *
 * Classe sem dependências do framework, para poder ser testada isoladamente.
 */
final class Jws
{
    public const CABECALHO = '{"alg":"RS256","typ":"JWT"}';

    public static function base64url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }

    public static function deBase64url(string $texto): ?string
    {
        if ($texto === '' || preg_match('/^[A-Za-z0-9_-]+$/', $texto) !== 1) {
            return null;
        }

        $decodificado = base64_decode(strtr($texto, '-_', '+/'), true);

        return $decodificado === false ? null : $decodificado;
    }

    /**
     * JSON canónico: sem espaços nem quebras de linha, aspas duplas, números
     * sem formatação adicional e a ordem das chaves exactamente como recebida.
     *
     * @param  array<string|int, mixed>  $dados
     */
    public static function canonico(array $dados): string
    {
        return self::codificar($dados);
    }

    /** Assina um payload JSON (já canónico) e devolve o JWS compacto. */
    public static function assinar(string $payloadJson, OpenSSLAsymmetricKey $chavePrivada): string
    {
        $entrada = self::base64url(self::CABECALHO).'.'.self::base64url($payloadJson);
        $assinatura = '';

        if (! openssl_sign($entrada, $assinatura, $chavePrivada, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('Falha OpenSSL ao assinar: '.(openssl_error_string() ?: 'erro desconhecido'));
        }

        return $entrada.'.'.self::base64url($assinatura);
    }

    /** Verifica a assinatura RS256 de um JWS compacto. */
    public static function verificar(string $jws, OpenSSLAsymmetricKey $chavePublica): bool
    {
        $partes = explode('.', $jws);

        if (count($partes) !== 3) {
            return false;
        }

        if (self::deBase64url($partes[0]) !== self::CABECALHO) {
            return false;
        }

        $assinatura = self::deBase64url($partes[2]);

        if ($assinatura === null) {
            return false;
        }

        return openssl_verify($partes[0].'.'.$partes[1], $assinatura, $chavePublica, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Devolve o payload (JSON) de um JWS compacto, sem validar a assinatura. */
    public static function payload(string $jws): ?string
    {
        $partes = explode('.', $jws);

        return count($partes) === 3 ? self::deBase64url($partes[1]) : null;
    }

    private static function codificar(mixed $valor): string
    {
        return match (true) {
            $valor instanceof NumeroJson => $valor->literal,
            is_array($valor) => self::codificarArray($valor),
            is_int($valor) => (string) $valor,
            is_bool($valor) => $valor ? 'true' : 'false',
            $valor === null => 'null',
            is_float($valor) => throw new InvalidArgumentException('Usa NumeroJson para valores decimais (nunca float).'),
            default => json_encode((string) $valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    }

    /** @param  array<string|int, mixed>  $dados */
    private static function codificarArray(array $dados): string
    {
        if (array_is_list($dados)) {
            return '['.implode(',', array_map(self::codificar(...), $dados)).']';
        }

        $itens = [];
        foreach ($dados as $chave => $valor) {
            $itens[] = json_encode((string) $chave, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).':'.self::codificar($valor);
        }

        return '{'.implode(',', $itens).'}';
    }
}
EOF_FASE1_1
mkdir -p Modules/Faturacao/app/Support
cat > Modules/Faturacao/app/Support/PayloadAssinaturaAgt.php <<'EOF_FASE1_2'
<?php

namespace Modules\Faturacao\Support;

/**
 * Objecto assinado em jwsDocumentSignature, segundo a especificação da API de
 * Facturação Electrónica da AGT (serviço "Registar Factura"): documentNo,
 * taxRegistrationNumber, documentType, documentDate, customerTaxID,
 * customerCountry, companyName e documentTotals {taxPayable, netTotal,
 * grossTotal}. Assina-se o objecto inteiro, em JSON canónico.
 */
final class PayloadAssinaturaAgt
{
    /** NIF genérico previsto pela AGT para compradores domésticos sem identificação. */
    public const NIF_CONSUMIDOR_FINAL = '999999999';

    /**
     * @param  int  $impostoCentimos  taxPayable
     * @param  int  $liquidoCentimos  netTotal
     * @param  int  $brutoCentimos  grossTotal
     */
    public static function json(
        string $numeroDocumento,
        string $nifEmissor,
        string $tipoDocumento,
        string $dataDocumento,
        ?string $nifCliente,
        ?string $paisCliente,
        string $nomeCliente,
        int $impostoCentimos,
        int $liquidoCentimos,
        int $brutoCentimos,
    ): string {
        $nif = trim((string) $nifCliente);
        $pais = strtoupper(trim((string) $paisCliente)) ?: 'AO';

        return Jws::canonico([
            'documentNo' => $numeroDocumento,
            'taxRegistrationNumber' => $nifEmissor,
            'documentType' => $tipoDocumento,
            'documentDate' => $dataDocumento,
            'customerTaxID' => $nif !== '' ? $nif : self::NIF_CONSUMIDOR_FINAL,
            'customerCountry' => $pais,
            'companyName' => trim($nomeCliente) !== '' ? trim($nomeCliente) : 'Consumidor final',
            'documentTotals' => [
                'taxPayable' => NumeroJson::deCentimos($impostoCentimos),
                'netTotal' => NumeroJson::deCentimos($liquidoCentimos),
                'grossTotal' => NumeroJson::deCentimos($brutoCentimos),
            ],
        ]);
    }
}
EOF_FASE1_2
mkdir -p Modules/Faturacao/app/Traits
cat > Modules/Faturacao/app/Traits/AssinavelAgt.php <<'EOF_FASE1_3'
<?php

namespace Modules\Faturacao\Traits;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\PayloadAssinaturaAgt;

/**
 * Constrói o objecto JSON canónico assinado em jwsDocumentSignature para os
 * documentos fiscais (Fatura, NotaCreditoDebito, Recibo). Lê empresa e cliente
 * sem depender do tenant ambiente (emissão corre em jobs, comandos e testes).
 */
trait AssinavelAgt
{
    protected function payloadAgtJson(int $impostoCentimos, int $liquidoCentimos, int $brutoCentimos): string
    {
        $empresa = Empresa::query()->find($this->empresa_id);
        $cliente = Cliente::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->find($this->cliente_id);

        if (! $empresa || trim((string) $empresa->nif) === '') {
            throw AssinaturaFiscalException::dadosEmFalta('A empresa emissora não tem NIF configurado.');
        }

        if (! $cliente) {
            throw AssinaturaFiscalException::dadosEmFalta('O cliente do documento não pertence a esta empresa.');
        }

        return PayloadAssinaturaAgt::json(
            numeroDocumento: (string) $this->numero_documento,
            nifEmissor: (string) $empresa->nif,
            tipoDocumento: $this->tipoDocumentoFiscal(),
            dataDocumento: $this->data_emissao->format('Y-m-d'),
            nifCliente: $cliente->nif,
            paisCliente: $cliente->pais,
            nomeCliente: (string) $cliente->nome,
            impostoCentimos: $impostoCentimos,
            liquidoCentimos: $liquidoCentimos,
            brutoCentimos: $brutoCentimos,
        );
    }
}
EOF_FASE1_3
mkdir -p Modules/Faturacao/app/Services
cat > Modules/Faturacao/app/Services/AssinaturaFiscalService.php <<'EOF_FASE1_4'
<?php

namespace Modules\Faturacao\Services;

use DateTimeInterface;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Support\Jws;
use OpenSSLAsymmetricKey;

/**
 * Assina cada documento como JWS compacto RS256 (RSA + SHA-256), segundo a
 * especificação da API de Facturação Electrónica da AGT: o conteúdo assinado
 * é o objecto JSON canónico do documento (ver Support/PayloadAssinaturaAgt) e
 * a chave RSA tem pelo menos 2048 bits.
 *
 * Esta classe não sabe nada sobre Fatura, NotaCreditoDebito ou Recibo — só
 * fala com DocumentoFiscalInterface. O JWS completo é guardado na coluna
 * `hash` do documento; `hash_anterior` é só cadeia interna (deteção de
 * lacunas) e não faz parte do objecto assinado.
 */
class AssinaturaFiscalService
{
    /** @var array<string, OpenSSLAsymmetricKey> */
    protected array $chaves = [];

    /**
     * Orquestra as duas fases do model: prepara os campos de emissão,
     * constrói o objecto a assinar, assina, e só então confirma a emissão.
     */
    public function assinarEEmitir(
        DocumentoFiscalInterface $documento,
        int $numeroSequencial,
        string $numeroDocumento,
        DateTimeInterface $dataHoraSistema,
        ?string $hashAnterior,
    ): void {
        $documento->prepararParaEmissao($numeroSequencial, $numeroDocumento, $dataHoraSistema, $hashAnterior);

        $jws = $this->assinar($documento->construirStringParaAssinar(), $documento->getEmpresaId());

        $documento->confirmarEmissao($jws, (int) config('faturacao.chave_versao'));
    }

    /**
     * Assina um payload JSON (já canónico) e devolve o JWS compacto. Usa a
     * chave da empresa (`{chaves_dir}/{NIF}.pem`) se existir; senão, a chave
     * por omissão. Público para poderes testar/depurar isoladamente.
     */
    public function assinar(string $payloadJson, ?int $empresaId = null): string
    {
        $chave = $this->carregarChavePrivada($empresaId);

        try {
            return Jws::assinar($payloadJson, $chave);
        } catch (\InvalidArgumentException $e) {
            throw AssinaturaFiscalException::falhaAoAssinar($e->getMessage());
        }
    }

    /**
     * Verifica um JWS contra a chave pública e confirma que o objecto
     * assinado é exactamente o esperado — útil em testes e na auditoria
     * independente (faturacao:verificar-cadeia).
     */
    public function verificar(string $conteudo, string $jws, string $caminhoChavePublica): bool
    {
        if (! is_file($caminhoChavePublica)) {
            throw AssinaturaFiscalException::chavePublicaNaoEncontrada();
        }

        $chavePublica = openssl_pkey_get_public((string) file_get_contents($caminhoChavePublica));

        if (! $chavePublica) {
            throw AssinaturaFiscalException::chavePublicaInvalida();
        }

        return Jws::verificar($jws, $chavePublica) && Jws::payload($jws) === $conteudo;
    }

    protected function carregarChavePrivada(?int $empresaId): OpenSSLAsymmetricKey
    {
        $caminho = $this->caminhoDaChave($empresaId);

        if (isset($this->chaves[$caminho])) {
            return $this->chaves[$caminho];
        }

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

        return $this->chaves[$caminho] = $chave;
    }

    protected function caminhoDaChave(?int $empresaId): string
    {
        if ($empresaId !== null) {
            $nif = (string) Empresa::query()->whereKey($empresaId)->value('nif');
            // Só caracteres seguros no nome do ficheiro (sem '/', '..' etc.).
            $nif = preg_replace('/[^A-Za-z0-9]/', '', $nif) ?? '';

            if ($nif !== '') {
                $porEmpresa = rtrim((string) config('faturacao.chaves_dir'), '/\\').DIRECTORY_SEPARATOR.$nif.'.pem';

                if (is_file($porEmpresa)) {
                    return $porEmpresa;
                }
            }
        }

        return (string) config('faturacao.chave_privada_path');
    }
}
EOF_FASE1_4
mkdir -p Modules/Faturacao/database/migrations
cat > Modules/Faturacao/database/migrations/2026_10_13_000011_add_pais_to_clientes_table.php <<'EOF_FASE1_5'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * customerCountry (assinado em cada documento): código ISO 3166-1 alpha-2
     * do país do cliente; "AO" por omissão. Fase 1 da adequação à AGT.
     */
    public function up(): void
    {
        if (Schema::hasColumn('clientes', 'pais')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('pais', 2)->default('AO')->after('nif');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('clientes', 'pais')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->dropColumn('pais');
            });
        }
    }
};
EOF_FASE1_5
mkdir -p Modules/Faturacao/tests/Unit
cat > Modules/Faturacao/tests/Unit/JwsTest.php <<'EOF_FASE1_6'
<?php

namespace Modules\Faturacao\Tests\Unit;

use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\NumeroJson;
use Modules\Faturacao\Support\PayloadAssinaturaAgt;
use PHPUnit\Framework\TestCase;

class JwsTest extends TestCase
{
    protected function chave(int $bits = 2048): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public function test_payload_segue_a_especificacao_da_agt_com_numeros_sem_formatacao(): void
    {
        $json = PayloadAssinaturaAgt::json(
            'FT FT6325S2C/10006', '5001636863', 'FT', '2025-11-04', 'PT987654321', 'PT', 'Cliente Exemplo Lda', 7000, 50000, 57000,
        );

        $this->assertSame(
            '{"documentNo":"FT FT6325S2C/10006","taxRegistrationNumber":"5001636863","documentType":"FT","documentDate":"2025-11-04",'
            .'"customerTaxID":"PT987654321","customerCountry":"PT","companyName":"Cliente Exemplo Lda",'
            .'"documentTotals":{"taxPayable":70,"netTotal":500,"grossTotal":570}}',
            $json,
        );
    }

    public function test_comprador_sem_nif_usa_999999999_e_pais_por_omissao_ao(): void
    {
        $dados = json_decode(PayloadAssinaturaAgt::json('FT A2026/1', '300000001', 'FT', '2026-10-13', null, null, '', 0, 0, 0), true);

        $this->assertSame('999999999', $dados['customerTaxID']);
        $this->assertSame('AO', $dados['customerCountry']);
        $this->assertSame('Consumidor final', $dados['companyName']);
    }

    public function test_numero_json_de_centimos(): void
    {
        $this->assertSame('0', NumeroJson::deCentimos(0)->literal);
        $this->assertSame('0.05', NumeroJson::deCentimos(5)->literal);
        $this->assertSame('0.1', NumeroJson::deCentimos(10)->literal);
        $this->assertSame('16.5', NumeroJson::deCentimos(1650)->literal);
        $this->assertSame('570', NumeroJson::deCentimos(57000)->literal);
        $this->assertSame('1140.01', NumeroJson::deCentimos(114001)->literal);
    }

    public function test_jws_rs256_assina_e_verifica(): void
    {
        $chave = $this->chave();
        $publica = openssl_pkey_get_public(openssl_pkey_get_details($chave)['key']);
        $json = PayloadAssinaturaAgt::json('FT A2026/1', '300000001', 'FT', '2026-10-13', null, null, 'X', 140, 1000, 1140);

        $jws = Jws::assinar($json, $chave);
        $partes = explode('.', $jws);

        $this->assertCount(3, $partes);
        $this->assertSame('{"alg":"RS256","typ":"JWT"}', Jws::deBase64url($partes[0]));
        $this->assertSame($json, Jws::payload($jws));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $jws, 'Base64URL: sem +, / nem padding.');
        $this->assertTrue(Jws::verificar($jws, $publica));
    }

    public function test_jws_adulterado_ou_com_outra_chave_nao_verifica(): void
    {
        $chave = $this->chave();
        $publica = openssl_pkey_get_public(openssl_pkey_get_details($chave)['key']);
        $outraPublica = openssl_pkey_get_public(openssl_pkey_get_details($this->chave())['key']);
        $jws = Jws::assinar('{"documentNo":"FT A2026/1"}', $chave);

        $partes = explode('.', $jws);
        $partes[1] = Jws::base64url('{"documentNo":"FT A2026/2"}');

        $this->assertFalse(Jws::verificar(implode('.', $partes), $publica));
        $this->assertFalse(Jws::verificar($jws, $outraPublica));
        $this->assertFalse(Jws::verificar('lixo', $publica));
    }

    public function test_iva_e_arredondado_por_excesso(): void
    {
        $this->assertSame('0.15', Dinheiro::calcularLinha(1, '1.01', 14)['iva']); // 0.1414 => 0.15
        $this->assertSame('0.05', Dinheiro::calcularLinha(3, '0.10', 14)['iva']); // 0.042  => 0.05
        $this->assertSame('14.00', Dinheiro::calcularLinha(1, '100.00', 14)['iva']); // exato, não sobe
        $this->assertSame('8.40', Dinheiro::calcularLinha(3, '19.99', 14)['iva']); // 8.3958 => 8.40
        $this->assertSame('68.37', Dinheiro::calcularLinha(3, '19.99', 14)['total']);
    }
}
EOF_FASE1_6
mkdir -p Modules/Faturacao/tests/Feature
cat > Modules/Faturacao/tests/Feature/AssinaturaJwsTest.php <<'EOF_FASE1_7'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

class AssinaturaJwsTest extends TestCase
{
    use RefreshDatabase;

    protected string $privada;

    protected string $publica;

    protected string $dirChaves;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->privada, $this->publica] = $this->gerarPar(2048);
        $this->dirChaves = sys_get_temp_dir().'/faturacao-chaves-'.uniqid();

        config([
            'faturacao.chave_privada_path' => $this->privada,
            'faturacao.chaves_dir' => $this->dirChaves,
        ]);

        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->dirChaves.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dirChaves);
        });
    }

    /** @return array{0: string, 1: string} caminhos [privada, publica] */
    protected function gerarPar(int $bits): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/faturacao-jws-'.uniqid().'.pem';
        $publica = sys_get_temp_dir().'/faturacao-jws-pub-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        file_put_contents($publica, openssl_pkey_get_details($recurso)['key']);

        $this->beforeApplicationDestroyed(function () use ($privada, $publica) {
            @unlink($privada);
            @unlink($publica);
        });

        return [$privada, $publica];
    }

    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja JWS '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function emitir(Empresa $empresa, Cliente $cliente): Fatura
    {
        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return $servico->emitir($servico->criarRascunho($empresa, $cliente, $linhas));
    }

    public function test_fatura_emitida_guarda_jws_rs256_com_o_objecto_da_agt(): void
    {
        [$empresa, $cliente] = $this->cenario('320000001');
        $fatura = $this->emitir($empresa, $cliente);

        $partes = explode('.', $fatura->hash);
        $this->assertCount(3, $partes);
        $this->assertSame('{"alg":"RS256","typ":"JWT"}', Jws::deBase64url($partes[0]));

        $payload = json_decode(Jws::payload($fatura->hash), true);
        $this->assertSame($fatura->numero_documento, $payload['documentNo']);
        $this->assertSame('320000001', $payload['taxRegistrationNumber']);
        $this->assertSame('FT', $payload['documentType']);
        $this->assertSame('999999999', $payload['customerTaxID']);
        $this->assertSame('AO', $payload['customerCountry']);
        $this->assertSame('Cliente Teste', $payload['companyName']);
        $this->assertSame(['taxPayable' => 140, 'netTotal' => 1000, 'grossTotal' => 1140], $payload['documentTotals']);
    }

    public function test_assinatura_verifica_com_a_chave_publica_e_falha_se_adulterada(): void
    {
        [$empresa, $cliente] = $this->cenario('320000002');
        $fatura = $this->emitir($empresa, $cliente);
        $servico = app(AssinaturaFiscalService::class);

        $this->assertTrue($servico->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $this->publica));

        $adulterado = str_replace('1140', '1141', Jws::payload($fatura->hash));
        $this->assertFalse($servico->verificar($adulterado, $fatura->hash, $this->publica));
    }

    public function test_chave_com_menos_de_2048_bits_e_recusada(): void
    {
        [$empresa, $cliente] = $this->cenario('320000003');
        [$fraca] = $this->gerarPar(1024);
        config(['faturacao.chave_privada_path' => $fraca]);

        $this->expectException(AssinaturaFiscalException::class);
        $this->expectExceptionMessage('2048');

        $this->emitir($empresa, $cliente);
    }

    public function test_cada_empresa_assina_com_a_sua_propria_chave_quando_existe(): void
    {
        [$empresa, $cliente] = $this->cenario('320000004');
        [$privadaEmpresa, $publicaEmpresa] = $this->gerarPar(2048);

        mkdir($this->dirChaves, 0777, true);
        copy($privadaEmpresa, $this->dirChaves.'/320000004.pem');

        $fatura = $this->emitir($empresa, $cliente);
        $servico = app(AssinaturaFiscalService::class);

        $this->assertTrue($servico->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $publicaEmpresa));
        $this->assertFalse($servico->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $this->publica));
    }

    public function test_recibo_assina_imposto_proporcional_da_fatura_por_excesso(): void
    {
        [$empresa, $cliente] = $this->cenario('320000005');
        $fatura = $this->emitir($empresa, $cliente); // 1000 + 140 = 1140

        $recibo = app(ReciboService::class)->emitir($empresa->id, $cliente->id, '500.00', 'dinheiro', $fatura->id);
        $payload = json_decode(Jws::payload($recibo->hash), true);

        $this->assertSame('RC', $payload['documentType']);
        $this->assertEqualsWithDelta(61.41, $payload['documentTotals']['taxPayable'], 0.0001);
        $this->assertEqualsWithDelta(438.59, $payload['documentTotals']['netTotal'], 0.0001);
        $this->assertSame(500, $payload['documentTotals']['grossTotal']);
    }
}
EOF_FASE1_7
python3 - <<'EOF_PATCH_MAIN'
import re, sys, pathlib

M = pathlib.Path('Modules/Faturacao')

def ler(p): return p.read_text(encoding='utf-8')
def gravar(p, t): p.write_text(t, encoding='utf-8')

def substituir(texto, antigo, novo, ficheiro, obrigatorio=True):
    if novo in texto and antigo not in texto:
        return texto  # já aplicado (idempotente)
    if antigo not in texto:
        if obrigatorio:
            sys.exit(f'ERRO: bloco esperado não encontrado em {ficheiro}. Aborta sem alterar mais nada.')
        return texto
    return texto.replace(antigo, novo, 1)

# ---------- Modelos: payload AGT em vez da string concatenada ----------
alvos = {
    'Fatura.php': ('valor_iva', 'valor_sem_iva', 'valor_total'),
    'NotaCreditoDebito.php': ('valor_iva', 'valor_sem_iva', 'valor_total'),
    'Recibo.php': None,
}
padrao = re.compile(r"    public function construirStringParaAssinar\(\): string\n    \{\n.*?\n    \}\n", re.S)

corpo_doc = """    /**
     * Objecto JSON canónico assinado em jwsDocumentSignature (API de Facturação
     * Electrónica da AGT): documentNo, taxRegistrationNumber, documentType,
     * documentDate, customerTaxID, customerCountry, companyName e
     * documentTotals. Ver Support/PayloadAssinaturaAgt.
     */
    public function construirStringParaAssinar(): string
    {
        return $this->payloadAgtJson(
            Dinheiro::centimos($this->valor_iva),
            Dinheiro::centimos($this->valor_sem_iva),
            Dinheiro::centimos($this->valor_total),
        );
    }
"""
corpo_recibo = """    /**
     * Objecto JSON canónico assinado em jwsDocumentSignature (API de Facturação
     * Electrónica da AGT). Num recibo, o imposto é o IVA proporcional da fatura
     * regularizada (arredondado por excesso, como o resto do IVA); sem fatura
     * (adiantamento), o valor é líquido e o imposto 0. A AGT apura este total
     * a partir dos documentos de origem — confirmar na homologação.
     */
    public function construirStringParaAssinar(): string
    {
        $bruto = Dinheiro::centimos($this->valor);
        $imposto = 0;

        $fatura = $this->fatura_id
            ? Fatura::withoutGlobalScopes()->where('empresa_id', $this->empresa_id)->find($this->fatura_id)
            : null;

        if ($fatura && Dinheiro::centimos($fatura->valor_total) > 0) {
            $totalFatura = Dinheiro::centimos($fatura->valor_total);
            $imposto = intdiv($bruto * Dinheiro::centimos($fatura->valor_iva) + $totalFatura - 1, $totalFatura);
        }

        return $this->payloadAgtJson($imposto, $bruto - $imposto, $bruto);
    }
"""

for nome in alvos:
    f = M / 'app/Models' / nome
    t = ler(f)
    if 'AssinavelAgt' not in t:
        corpo = corpo_recibo if nome == 'Recibo.php' else corpo_doc
        t, n = padrao.subn(lambda _m: corpo, t, count=1)
        if n != 1:
            sys.exit(f'ERRO: construirStringParaAssinar não encontrado em {nome}.')
        t = substituir(t, 'use Modules\\Faturacao\\Contracts\\DocumentoFiscalInterface;\n',
                       'use Modules\\Faturacao\\Contracts\\DocumentoFiscalInterface;\nuse Modules\\Faturacao\\Support\\Dinheiro;\nuse Modules\\Faturacao\\Traits\\AssinavelAgt;\n', nome)
        t = substituir(t, '    use BelongsToTenant;\n    use Imutavel;\n', '    use AssinavelAgt;\n    use BelongsToTenant;\n    use Imutavel;\n', nome)
        gravar(f, t)

# ---------- Dinheiro: IVA arredondado por excesso ----------
f = M / 'app/Support/Dinheiro.php'
t = ler(f)
t = substituir(t, 'sem passar por float na conversão (o texto decimal é lido dígito a\n * dígito). Valores NÃO negativos; arredondamento "half up".',
               'sem passar por float na conversão (o texto decimal é lido dígito a\n * dígito). Valores NÃO negativos. Arredondamento "half up" nos valores base e\n * POR EXCESSO no imposto: a AGT exige que taxContribution seja arredondado\n * por excesso ao cêntimo (ex.: 23.144 => 23.15).', 'Dinheiro.php')
t = substituir(t, '$iva = intdiv($semIva * $taxa + 5000, 10000);', '$iva = intdiv($semIva * $taxa + 9999, 10000); // por excesso (AGT)', 'Dinheiro.php')
gravar(f, t)

# ---------- Teste existente que fixava o arredondamento antigo ----------
f = M / 'tests/Feature/RegrasDeNegocioTest.php'
t = ler(f)
t = substituir(t, "['sem_iva' => '0.30', 'iva' => '0.04', 'total' => '0.34'], Dinheiro::calcularLinha(3, '0.10', 14));",
               "['sem_iva' => '0.30', 'iva' => '0.05', 'total' => '0.35'], Dinheiro::calcularLinha(3, '0.10', 14)); // 0.042 => 0.05 (IVA por excesso, AGT)", 'RegrasDeNegocioTest.php')
gravar(f, t)

# ---------- Cliente: país (customerCountry) ----------
f = M / 'app/Models/Cliente.php'
t = ler(f)
t = substituir(t, "        'nif',\n        'morada',", "        'nif',\n        'pais',\n        'morada',", 'Cliente.php')
gravar(f, t)

# ---------- Config ----------
NOVO_BLOCO_CONFIG = r"""    /*
    |--------------------------------------------------------------------------
    | Assinatura digital (JWS RS256) — Facturação Electrónica da AGT
    |--------------------------------------------------------------------------
    |
    | Segundo a especificação oficial da AGT (quiosque AGT > Facturação
    | Electrónica > Estrutura das Assinaturas Digitais), cada documento é
    | assinado como JWS compacto, algoritmo RS256 (RSA + SHA-256), sobre o
    | objecto JSON do documento, com chave RSA de pelo menos 2048 bits.
    |
    | As chaves do CONTRIBUINTE são emitidas pela AGT no Portal do Contribuinte
    | ao aderir à faturação eletrónica (uma por NIF). Guarda a chave privada de
    | cada empresa em:
    |   {chaves_dir}/{NIF}.pem
    | e só se não existir esse ficheiro é usada a chave por omissão abaixo
    | (útil em desenvolvimento e testes). NUNCA committes chaves para o Git.
    |
    | A chave do SOFTWARE (jwsSoftwareSignature) é outra, gerada pelo produtor;
    | entra na Fase 2 (comunicação com a AGT).
    */
    // `?:` e não o 2.º argumento de env(): uma variável presente mas vazia
    // devolve '' e não usaria o valor por omissão.
    'chave_privada_path' => env('FATURACAO_CHAVE_PRIVADA_PATH') ?: storage_path('app/faturacao/chave-privada.pem'),

    'chaves_dir' => env('FATURACAO_CHAVES_DIR') ?: storage_path('app/faturacao/chaves'),

    /*
    | Tamanho mínimo aceite para chaves RSA de assinatura (bits). A AGT
    | recomenda não usar chaves inferiores a 2048 bits.
    */
    'chave_minima_bits' => 2048,

    /*
    | Versão da chave em uso — nº inteiro sequencial registado em cada
    | documento, para permitir rotação de chaves sem invalidar documentos
    | antigos.
    */
    'chave_versao' => (int) env('FATURACAO_CHAVE_VERSAO', 1),

"""

f = M / 'config/config.php'
t = ler(f)
if 'chaves_dir' not in t:
    ini = t.index("    /*\n    |--------------------------------------------------------------------------\n    | Chave RSA do produtor de software")
    fim = t.index("    /*\n    |--------------------------------------------------------------------------\n    | Séries de documentos")
    t = t[:ini] + NOVO_BLOCO_CONFIG + t[fim:]
    gravar(f, t)

# ---------- README ----------
f = M / 'README.md'
t = ler(f)
if 'Assinatura JWS (AGT)' not in t:
    t += """

## Assinatura JWS (AGT) — regime atual

Desde a Fase 1 da adequação à Facturação Electrónica (Decreto Presidencial
71/25 e Decreto Executivo 683/25), cada documento é assinado segundo a
especificação oficial da AGT (quiosque AGT > Facturação Electrónica):

- **JWS compacto, RS256** (RSA + SHA-256), cabeçalho `{"alg":"RS256","typ":"JWT"}`,
  Base64URL sem padding, **chave RSA de pelo menos 2048 bits** (chaves mais
  fracas são recusadas).
- O que se assina é o **objecto JSON do documento** (`documentNo`,
  `taxRegistrationNumber`, `documentType`, `documentDate`, `customerTaxID`,
  `customerCountry`, `companyName`, `documentTotals`), em JSON canónico — não
  uma string concatenada. Comprador sem NIF => `999999999`.
- O JWS completo fica na coluna `hash` de cada documento (a antiga assinatura
  RSA-SHA1 de 172 caracteres, do Ofício Circulado 50001/2013, **já não se usa**).
  `hash_anterior` mantém-se apenas como cadeia interna para deteção de lacunas;
  não faz parte do objecto assinado.
- **O IVA é arredondado por excesso** ao cêntimo (regra do campo `taxContribution`).
- Chaves por empresa: `storage/app/faturacao/chaves/{NIF}.pem` (emitidas pela AGT
  no Portal do Contribuinte); sem esse ficheiro usa-se a chave por omissão.

Ainda **não** feito (fases seguintes): pedido de séries à AGT, envio
`registarFactura`, QR Code, tipos de operação, códigos de isenção e SAF-T(AO).
Este módulo continua a não substituir a certificação do software pela AGT.
"""
    gravar(f, t)


# ---------- Limpeza de comentários antigos ----------
f = M / 'app/Models/Fatura.php'
t = ler(f)
antigo_doc = """    /**
     * Formato reconstruído a partir da família de especificações SAF-T
     * lusófona a que o mecanismo da AGT está alinhado (Portaria 363/2010):
     * data;data-hora-sistema;identificador-documento;valor-total;hash-anterior.
     * CONFIRMA isto contra a documentação técnica atual da AGT antes de
     * assinares um único documento real com esta chave — ver README.
     */
"""
t = t.replace(antigo_doc, '', 1)
gravar(f, t)

f = M / 'app/Contracts/DocumentoFiscalInterface.php'
t = ler(f)
t = substituir(t, """     * String canónica usada como entrada da assinatura RSA. Cada model
     * decide os seus próprios campos (data, número, valores, hash
     * anterior) — ver AssinaturaFiscalService::assinar() para a construção
     * exata e o aviso sobre confirmar o formato contra a AGT.""",
"""     * Objecto JSON canónico assinado como JWS RS256 (jwsDocumentSignature da
     * API de Facturação Electrónica da AGT): documentNo, taxRegistrationNumber,
     * documentType, documentDate, customerTaxID, customerCountry, companyName e
     * documentTotals. Ver Support/PayloadAssinaturaAgt e AssinaturaFiscalService.""", 'DocumentoFiscalInterface.php')
gravar(f, t)

f = pathlib.Path('.env.example')
if f.exists():
    t = ler(f)
    if 'FATURACAO_CHAVES_DIR' not in t:
        t = substituir(t, '# FATURACAO_CHAVE_PRIVADA_PATH=\n', '# FATURACAO_CHAVE_PRIVADA_PATH=\n# Pasta com as chaves privadas emitidas pela AGT, uma por empresa: {NIF}.pem\n# FATURACAO_CHAVES_DIR=\n', '.env.example')
        gravar(f, t)

print('Patches aplicados com sucesso.')

EOF_PATCH_MAIN
python3 - <<'EOF_PATCH_EXC'
import sys, pathlib
f = pathlib.Path('Modules/Faturacao/app/Exceptions/AssinaturaFiscalException.php')
t = f.read_text(encoding='utf-8')
if 'chaveFraca' not in t:
    novo = """
    public static function chaveFraca(int $bits, int $minimo): self
    {
        return new self("A chave RSA de assinatura tem {$bits} bits; a AGT exige pelo menos {$minimo} bits.");
    }

    public static function dadosEmFalta(string $motivo): self
    {
        return new self('Não é possível assinar o documento: '.$motivo);
    }
}
"""
    idx = t.rstrip().rfind('}')
    t = t[:idx].rstrip('\n') + '\n' + novo
    f.write_text(t, encoding='utf-8')
print('Excecoes OK')

EOF_PATCH_EXC

echo "== Sintaxe PHP =="
for f in $(git status --porcelain | awk '{print $2}' | grep '\.php$'); do php -l "$f" >/dev/null; done
for f in Modules/Faturacao/tests/Unit/JwsTest.php; do php -l "$f" >/dev/null; done

echo "== Estilo (Pint) =="
vendor/bin/pint Modules/Faturacao

echo "== Migração (base de dados local) =="
php artisan migrate --force || echo "AVISO: migrate falhou na BD local; os testes usam SQLite em memória e continuam."

echo "== Testes (todos) =="
php artisan test

echo "== Commit e push direto para main =="
git add -A
git commit -m "feat(faturacao): assinatura JWS RS256 da AGT, payload oficial e IVA por excesso" \
  -m "Fase 1 da adequação à Facturação Electrónica (DP 71/25, DE 683/25): JWS RS256 com chave RSA >= 2048 bits sobre o objecto JSON do documento, chave por empresa ({NIF}.pem), customerCountry, IVA arredondado por excesso. Substitui a assinatura RSA-SHA1 antiga."
git push origin main
echo "FASE 1 CONCLUÍDA e enviada para main."
)
