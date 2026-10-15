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
