<?php

namespace Modules\Subscricoes\Services;

/**
 * Valida a configuração do gateway antes de se cobrar a clientes.
 * Devolve uma lista de erros (bloqueantes) e avisos (não bloqueantes).
 */
class ConfiguracaoPagamentoValidator
{
    /**
     * @param  array<string, mixed>  $gateway  config('subscricoes.gateways.proxypay')
     * @return array{erros: list<string>, avisos: list<string>}
     */
    public function validar(array $gateway, bool $producao): array
    {
        $erros = [];
        $avisos = [];

        $apiKey = trim((string) ($gateway['api_key'] ?? ''));
        $entidade = trim((string) ($gateway['entity_id'] ?? ''));
        $baseUrl = trim((string) ($gateway['base_url'] ?? ''));

        if ($apiKey === '') {
            $erros[] = 'PROXYPAY_API_KEY não está definida.';
        }

        if ($entidade === '') {
            $erros[] = 'PROXYPAY_ENTITY_ID não está definida: o cliente não consegue pagar sem a entidade Multicaixa.';
        } elseif (! preg_match('/^\d{3,10}$/', $entidade)) {
            $erros[] = 'PROXYPAY_ENTITY_ID deve ser numérica (a entidade Multicaixa).';
        }

        if ($baseUrl === '' || ! str_starts_with($baseUrl, 'https://')) {
            $erros[] = 'PROXYPAY_BASE_URL deve ser um URL https válido.';
        }

        $ehSandbox = str_contains(strtolower($baseUrl), 'sandbox');

        if ($producao && $ehSandbox) {
            $erros[] = 'Ambiente de produção a usar a sandbox da ProxyPay (PROXYPAY_BASE_URL).';
        }

        if (! $producao && $baseUrl !== '' && ! $ehSandbox) {
            $avisos[] = 'Ambiente não produtivo a apontar para a API real da ProxyPay: confirma que não é engano.';
        }

        return ['erros' => $erros, 'avisos' => $avisos];
    }
}
