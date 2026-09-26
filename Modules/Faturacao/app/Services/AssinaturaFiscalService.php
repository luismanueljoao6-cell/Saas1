<?php

namespace Modules\Faturacao\Services;

use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;

/**
 * Aplica o mecanismo descrito pela AGT (Ofício Circulado 50001/2013, com
 * base na Portaria n.º 363/2010): cada documento é assinado com RSA usando
 * a chave privada do produtor do software, com a assinatura do documento
 * ANTERIOR da mesma série/tipo como parte da string assinada — para o
 * primeiro documento de cada série, esse campo fica vazio.
 *
 * Esta classe não sabe nada sobre Fatura, NotaCreditoDebito ou Recibo —
 * só fala com DocumentoFiscalInterface. Quem decide qual é "o documento
 * anterior" (uma query à tabela certa) é o serviço de emissão de cada tipo
 * de documento (ex.: FaturaService), que depois entrega aqui o hash desse
 * documento anterior.
 */
class AssinaturaFiscalService
{
    protected ?\OpenSSLAsymmetricKey $chavePrivada = null;

    /**
     * Orquestra as duas fases do model: prepara os campos de emissão,
     * constrói a string, assina, e só então confirma a emissão.
     */
    public function assinarEEmitir(
        DocumentoFiscalInterface $documento,
        int $numeroSequencial,
        string $numeroDocumento,
        \DateTimeInterface $dataHoraSistema,
        ?string $hashAnterior,
    ): void {
        $documento->prepararParaEmissao($numeroSequencial, $numeroDocumento, $dataHoraSistema, $hashAnterior);

        $hash = $this->assinar($documento->construirStringParaAssinar());

        $documento->confirmarEmissao($hash, (int) config('faturacao.chave_versao'));
    }

    /**
     * Assina uma string com a chave privada RSA configurada, devolvendo a
     * assinatura em base64 (formato habitual de guardar/transmitir hashes
     * SAF-T). Método público à parte de assinarEEmitir() para poderes
     * testar/depurar a assinatura isoladamente.
     */
    public function assinar(string $conteudo): string
    {
        $chave = $this->carregarChavePrivada();

        $assinaturaBinaria = '';
        $sucesso = openssl_sign(
            $conteudo,
            $assinaturaBinaria,
            $chave,
            (int) config('faturacao.algoritmo_assinatura'),
        );

        if (! $sucesso) {
            throw AssinaturaFiscalException::falhaAoAssinar(openssl_error_string() ?: 'erro desconhecido do OpenSSL');
        }

        return base64_encode($assinaturaBinaria);
    }

    /**
     * Verifica uma assinatura contra a chave pública correspondente — útil
     * em testes e para uma futura ferramenta de auditoria/validação
     * independente das faturas emitidas.
     */
    public function verificar(string $conteudo, string $assinaturaBase64, string $caminhoChavePublica): bool
    {
        $chavePublica = openssl_pkey_get_public(file_get_contents($caminhoChavePublica));

        if (! $chavePublica) {
            throw AssinaturaFiscalException::chavePrivadaInvalida();
        }

        $resultado = openssl_verify(
            $conteudo,
            base64_decode($assinaturaBase64),
            $chavePublica,
            (int) config('faturacao.algoritmo_assinatura'),
        );

        return $resultado === 1;
    }

    protected function carregarChavePrivada(): \OpenSSLAsymmetricKey
    {
        if ($this->chavePrivada !== null) {
            return $this->chavePrivada;
        }

        $caminho = config('faturacao.chave_privada_path');

        if (! is_file($caminho)) {
            throw AssinaturaFiscalException::chavePrivadaNaoEncontrada($caminho);
        }

        $chave = openssl_pkey_get_private(file_get_contents($caminho));

        if (! $chave) {
            throw AssinaturaFiscalException::chavePrivadaInvalida();
        }

        return $this->chavePrivada = $chave;
    }
}
