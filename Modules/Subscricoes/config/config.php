<?php

return [

    'name' => 'Subscricoes',

    /*
    |--------------------------------------------------------------------------
    | Gateway de pagamento ativo
    |--------------------------------------------------------------------------
    |
    | 'proxypay' é o único gateway implementado nesta entrega (ver
    | app/Services/Gateways/ProxyPayGateway.php e o README para a razão da
    | escolha). Para adicionar outro (EMIS GPO direto, AppyPay, Ekwanza...),
    | cria uma nova classe que implemente GatewayPagamentoInterface e regista-a
    | aqui.
    |
    */
    'gateway' => env('SUBSCRICOES_GATEWAY', 'proxypay'),

    'gateways' => [
        'proxypay' => [
            'base_url' => env('PROXYPAY_BASE_URL', 'https://api.proxypay.co.ao'),
            'api_key' => env('PROXYPAY_API_KEY'),
            // Confirma o nome exato deste cabeçalho na tua conta ProxyPay —
            // varia consoante o mecanismo de assinatura de webhook em vigor.
            'webhook_token' => env('PROXYPAY_WEBHOOK_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Moeda
    |--------------------------------------------------------------------------
    */
    'moeda' => env('SUBSCRICOES_MOEDA', 'AOA'),

    /*
    |--------------------------------------------------------------------------
    | Validade da referência de pagamento (dias)
    |--------------------------------------------------------------------------
    */
    'validade_referencia_dias' => (int) env('SUBSCRICOES_VALIDADE_REFERENCIA_DIAS', 3),

    /*
    |--------------------------------------------------------------------------
    | Aviso de renovação
    |--------------------------------------------------------------------------
    | Quantos dias antes de terminar_em a VerificarSubscricoesExpiradasJob
    | deve disparar um lembrete (ver README — notificação de lembrete
    | deixada como próximo passo, o "gancho" já fica pronto aqui).
    */
    'aviso_renovacao_dias' => (int) env('SUBSCRICOES_AVISO_RENOVACAO_DIAS', 3),

];
