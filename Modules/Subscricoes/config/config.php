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
            // Os webhooks v2 usam X-Signature = HMAC-SHA-256 do corpo
            // original, assinado com a mesma API key da integração.
            // Número de entidade Multicaixa atribuído à tua conta ProxyPay
            // (aparece no talão de pagamento ao lado da referência).
            'entity_id' => env('PROXYPAY_ENTITY_ID'),
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
    | Preço aplicado nas renovações
    |--------------------------------------------------------------------------
    | 'contratado': a renovação mantém o preço da compra (snapshot na
    |               subscrição). Alterar o plano só afeta novas subscrições.
    | 'atual':      a renovação usa o preço atual do plano (exige comunicar a
    |               alteração ao cliente antes da renovação).
    */
    'renovacao_preco' => env('SUBSCRICOES_RENOVACAO_PRECO', 'contratado'),

    /*
    |--------------------------------------------------------------------------
    | Pedidos de pagamento abandonados (minutos)
    |--------------------------------------------------------------------------
    | Um pagamento 'pendente' sem referência externa só é legítimo durante a
    | chamada ao gateway (segundos). Passado este prazo considera-se que o
    | processo morreu a meio e o pedido é descartado, para a empresa poder
    | tentar de novo em vez de ficar bloqueada até a referência expirar.
    */
    'preparacao_abandonada_minutos' => (int) env('SUBSCRICOES_PREPARACAO_ABANDONADA_MINUTOS', 2),

];
