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
    | gerar a referência de renovação e avisar a empresa por e-mail.
    */
    'aviso_renovacao_dias' => (int) env('SUBSCRICOES_AVISO_RENOVACAO_DIAS', 3),

    /*
    |--------------------------------------------------------------------------
    | Limites de uma empresa SEM subscrição ativa (trial, por exemplo)
    |--------------------------------------------------------------------------
    | Com subscrição ativa valem os limites do plano (Plano::$limites).
    | Valores de partida — decisão comercial tua, ajusta à vontade.
    */
    'limites_sem_plano' => [
        'max_utilizadores' => (int) env('SUBSCRICOES_TRIAL_MAX_UTILIZADORES', 3),
        'max_faturas_mes' => (int) env('SUBSCRICOES_TRIAL_MAX_FATURAS_MES', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exigir o valor pago no webhook
    |--------------------------------------------------------------------------
    | true (recomendado): o webhook tem de trazer o valor pago e este não pode
    | ser inferior ao do pagamento, senão é recusado. Põe false SÓ enquanto
    | testas contra a sandbox da ProxyPay e confirmas o nome do campo.
    */
    'exigir_valor_webhook' => env('SUBSCRICOES_EXIGIR_VALOR_WEBHOOK', true),

];
