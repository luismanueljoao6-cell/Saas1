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
    public function softwareInfo(bool $incluirVersaoAssinatura = true): array
    {
        $detalhe = [
            'productId' => (string) config('faturacao.agt.software.product_id'),
            'productVersion' => (string) config('faturacao.agt.software.product_version'),
            'softwareValidationNumber' => (string) config('faturacao.saft_numero_certificado'),
        ];

        // O serviço registarFactura exige signatureVersion; o serviço de criação
        // de séries documenta apenas os três campos acima. A assinatura JWS deve
        // cobrir exactamente o objecto que cada endpoint espera.
        if ($incluirVersaoAssinatura) {
            $versao = config('faturacao.agt.software.signature_version');

            if (! is_numeric($versao) || (int) $versao < 1) {
                throw AgtException::configuracaoInvalida('FATURACAO_AGT_SIGNATURE_VERSION tem de conter a versão de assinatura activa registada para o software.');
            }

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
