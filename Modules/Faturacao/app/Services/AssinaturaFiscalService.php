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

        // Congela NIF, nome e país do cliente no documento antes de construir o objecto assinado.
        if (method_exists($documento, 'congelarCliente')) {
            $documento->congelarCliente();
        }

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

        return $this->verificarComPem($conteudo, $jws, (string) file_get_contents($caminhoChavePublica));
    }

    /** Como verificar(), com a chave pública já em PEM. */
    public function verificarComPem(string $conteudo, string $jws, string $chavePublicaPem): bool
    {
        $chavePublica = openssl_pkey_get_public($chavePublicaPem);

        if (! $chavePublica) {
            throw AssinaturaFiscalException::chavePublicaInvalida();
        }

        return Jws::verificar($jws, $chavePublica) && Jws::payload($jws) === $conteudo;
    }

    /**
     * Chave pública (PEM) derivada da chave privada DA EMPRESA ({chaves_dir}/{NIF}.pem),
     * ou null se essa chave não existe neste servidor. Nunca recorre à chave genérica.
     */
    public function chavePublicaDaEmpresa(int $empresaId): ?string
    {
        $caminho = $this->caminhoDaChaveDaEmpresa($empresaId);

        if ($this->nifSeguro($empresaId) === '' || ! is_file($caminho)) {
            return null;
        }

        $chave = openssl_pkey_get_private((string) file_get_contents($caminho));

        if (! $chave) {
            throw AssinaturaFiscalException::chavePrivadaInvalida();
        }

        $publica = (openssl_pkey_get_details($chave) ?: [])['key'] ?? null;

        return is_string($publica) ? $publica : null;
    }

    /** Caminho esperado da chave privada da empresa: {chaves_dir}/{NIF}.pem. */
    public function caminhoDaChaveDaEmpresa(int $empresaId): string
    {
        $nif = $this->nifSeguro($empresaId);

        return rtrim((string) config('faturacao.chaves_dir'), '/\\').DIRECTORY_SEPARATOR.($nif !== '' ? $nif : 'SEM-NIF').'.pem';
    }

    /** NIF só com caracteres seguros para um nome de ficheiro (sem '/', '..' etc.); '' se a empresa não tem NIF. */
    protected function nifSeguro(int $empresaId): string
    {
        $nif = (string) Empresa::query()->whereKey($empresaId)->value('nif');

        return preg_replace('/[^A-Za-z0-9]/', '', $nif) ?? '';
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
        // Sem empresa não há chave de emissor: fora de desenvolvimento/testes nunca se
        // assina (nem se assina um pedido à AGT) com a chave genérica.
        if ($empresaId === null && ! $this->permiteChaveGenerica()) {
            throw AssinaturaFiscalException::chavePrivadaNaoEncontrada('(sem empresa: a chave por NIF não pode ser resolvida)');
        }

        if ($empresaId !== null) {
            $porEmpresa = $this->caminhoDaChaveDaEmpresa($empresaId);

            if ($this->nifSeguro($empresaId) !== '' && is_file($porEmpresa)) {
                return $porEmpresa;
            }

            // Um documento fiscal de uma empresa nunca pode ser assinado com a
            // chave genérica fora de desenvolvimento/testes.
            if (! $this->permiteChaveGenerica()) {
                throw AssinaturaFiscalException::chavePrivadaNaoEncontrada($porEmpresa);
            }
        }

        return (string) config('faturacao.chave_privada_path');
    }

    /** A chave por omissão só é aceite em local/testing e com a AGT desligada. */
    protected function permiteChaveGenerica(): bool
    {
        return ! config('faturacao.agt.ativo')
            && app()->environment(['local', 'testing']);
    }
}
