<?php

return [

    'name' => 'EstudioMusica',

    /*
    |--------------------------------------------------------------------------
    | Tipos de serviço de sessão
    |--------------------------------------------------------------------------
    | Lista fechada pedida no requisito (secção B). Mesma ideia do
    | 'tipos_documento' do módulo Faturacao: uma chave estável guardada em
    | sessoes_estudio.tipo_servico, com o rótulo bonito só aqui.
    */
    'tipos_servico' => [
        'ensaio' => 'Ensaio',
        'gravacao_voz' => 'Gravação de Voz',
        'producao_completa' => 'Produção Completa',
        'mixagem' => 'Mixagem',
        'masterizacao' => 'Masterização',
        'locucao_podcast' => 'Locução/Podcast',
    ],

    /*
    |--------------------------------------------------------------------------
    | Estados do projeto/faixa
    |--------------------------------------------------------------------------
    | Fluxo de 8 estados pedido no requisito (secção A), na ordem em que
    | normalmente progridem — usado pelas views para desenhar o percurso.
    */
    'estados_projeto' => [
        'agendado' => 'Agendado',
        'gravacao' => 'Gravação / Captação',
        'edicao' => 'Edição / Afinação',
        'mixagem' => 'Mixagem',
        'masterizacao' => 'Masterização',
        'aprovacao_cliente' => 'Aprovação do Cliente',
        'concluido' => 'Concluído / Entregue',
        'cancelado' => 'Cancelado',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sinal / adiantamento
    |--------------------------------------------------------------------------
    | Percentagem por omissão cobrada para confirmar a agenda (secção E:
    | "30% a 50%"). Cada projeto pode substituir isto em
    | projetos_musicais.percentual_sinal.
    */
    'percentual_sinal_padrao' => (float) env('ESTUDIOMUSICA_PERCENTUAL_SINAL_PADRAO', 40.0),

    /*
    |--------------------------------------------------------------------------
    | Campanha de aniversário (secção F)
    |--------------------------------------------------------------------------
    */
    'percentual_desconto_aniversario' => (float) env('ESTUDIOMUSICA_DESCONTO_ANIVERSARIO', 15.0),
    'validade_cupom_aniversario_dias' => (int) env('ESTUDIOMUSICA_VALIDADE_CUPOM_DIAS', 30),

    /*
    |--------------------------------------------------------------------------
    | Lembretes de sessão (secção D)
    |--------------------------------------------------------------------------
    | Janelas de antecedência, em horas, em que EnviarLembretesSessaoJob
    | verifica sessões a começar. O job corre de hora a hora (ver
    | EstudioMusicaServiceProvider), por isso cada janela tem uma
    | tolerância de +/-30min para não depender do job correr ao segundo
    | exato.
    */
    'lembrete_horas_antes' => [24, 2],

    /*
    |--------------------------------------------------------------------------
    | Portal do cliente
    |--------------------------------------------------------------------------
    | Validade do token de acesso temporário (secção C). Gerado de novo
    | sempre que a Receção reenvia o link ao artista.
    */
    'portal_token_validade_dias' => (int) env('ESTUDIOMUSICA_PORTAL_TOKEN_VALIDADE_DIAS', 30),

    /*
    |--------------------------------------------------------------------------
    | Canais de notificação externos (secção D)
    |--------------------------------------------------------------------------
    | 'log' (por omissão) só regista a mensagem — seguro para desenvolvimento
    | e para este ambiente sem acesso à rede. Troca para 'whatsapp_cloud_api'
    | quando tiveres credenciais reais (ver
    | Notifications/Channels/WhatsAppCloudApiChannel — os nomes exatos dos
    | campos foram reconstruídos a partir da documentação pública da Meta,
    | não testados com uma conta real).
    */
    'canal_whatsapp' => env('ESTUDIOMUSICA_CANAL_WHATSAPP', 'log'),
    'canal_sms' => env('ESTUDIOMUSICA_CANAL_SMS', 'log'),

    'whatsapp_cloud_api' => [
        'base_url' => env('WHATSAPP_CLOUD_API_BASE_URL', 'https://graph.facebook.com/v21.0'),
        'phone_number_id' => env('WHATSAPP_CLOUD_API_PHONE_NUMBER_ID'),
        'token_acesso' => env('WHATSAPP_CLOUD_API_TOKEN'),
    ],

    'sms' => [
        // Reconstruído a partir da documentação pública da Twilio — o
        // fornecedor concreto é facilmente trocável (ver
        // Contracts\CanalMensagemInterface); confirma antes de produção.
        'account_sid' => env('SMS_TWILIO_ACCOUNT_SID'),
        'auth_token' => env('SMS_TWILIO_AUTH_TOKEN'),
        'numero_remetente' => env('SMS_TWILIO_NUMERO_REMETENTE'),
    ],

];
