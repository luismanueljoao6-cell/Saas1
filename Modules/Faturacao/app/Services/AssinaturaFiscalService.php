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
