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
