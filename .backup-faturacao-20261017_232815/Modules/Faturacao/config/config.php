<?php

return [

    'name' => 'Faturacao',

    /*
    |--------------------------------------------------------------------------
    | Assinatura digital (JWS RS256) — Facturação Electrónica da AGT
    |--------------------------------------------------------------------------
    |
    | Segundo a especificação oficial da AGT (quiosque AGT > Facturação
    | Electrónica > Estrutura das Assinaturas Digitais), cada documento é
    | assinado como JWS compacto, algoritmo RS256 (RSA + SHA-256), sobre o
    | objecto JSON do documento, com chave RSA de pelo menos 2048 bits.
    |
    | As chaves do CONTRIBUINTE são emitidas pela AGT no Portal do Contribuinte
    | ao aderir à faturação eletrónica (uma por NIF). Guarda a chave privada de
    | cada empresa em:
    |   {chaves_dir}/{NIF}.pem
    | e só se não existir esse ficheiro é usada a chave por omissão abaixo
    | (útil em desenvolvimento e testes). NUNCA committes chaves para o Git.
    |
    | A chave do SOFTWARE (jwsSoftwareSignature) é outra, gerada pelo produtor;
    | entra na Fase 2 (comunicação com a AGT).
    */
    // `?:` e não o 2.º argumento de env(): uma variável presente mas vazia
    // devolve '' e não usaria o valor por omissão.
    'chave_privada_path' => env('FATURACAO_CHAVE_PRIVADA_PATH') ?: storage_path('app/faturacao/chave-privada.pem'),

    'chaves_dir' => env('FATURACAO_CHAVES_DIR') ?: storage_path('app/faturacao/chaves'),

    /*
    | Tamanho mínimo aceite para chaves RSA de assinatura (bits). A AGT
    | recomenda não usar chaves inferiores a 2048 bits.
    */
    'chave_minima_bits' => 2048,

    /*
    | Versão da chave em uso — nº inteiro sequencial registado em cada
    | documento, para permitir rotação de chaves sem invalidar documentos
    | antigos.
    */
    'chave_versao' => (int) env('FATURACAO_CHAVE_VERSAO', 1),

    /*
    |--------------------------------------------------------------------------
    | Séries de documentos
    |--------------------------------------------------------------------------
    | Tipos de documento suportados nesta entrega. Cada um tem a sua própria
    | cadeia de numeração e de hash (a cadeia nunca mistura tipos/séries).
    */
    'tipos_documento' => [
        'FT' => 'Fatura',
        'NC' => 'Nota de Crédito',
        'ND' => 'Nota de Débito',
        'RC' => 'Recibo',
    ],

    /*
    |--------------------------------------------------------------------------
    | IVA
    |--------------------------------------------------------------------------
    | Taxa geral em vigor — confirma sempre contra a tabela de taxas atual
    | da AGT para o regime fiscal de cada empresa/produto; nem todos os bens
    | e serviços estão sujeitos à taxa geral.
    */
    'taxa_iva_geral' => (float) env('FATURACAO_TAXA_IVA_GERAL', 14.0),

    /*
    |--------------------------------------------------------------------------
    | Número de certificação do software (SAF-T)
    |--------------------------------------------------------------------------
    | Preenche isto com o número atribuído pela AGT quando o processo de
    | certificação do software estiver concluído — ver aviso no README
    | sobre este módulo, sozinho, não substituir essa certificação.
    | '0' é o valor de desenvolvimento/antes-de-certificado.
    */
    'saft_numero_certificado' => env('FATURACAO_SAFT_NUMERO_CERTIFICADO', '0'),

    /*
    | Taxas de IVA aceites na emissão (percentagens, separadas por vírgula no .env).
    | Confirma contra a tabela atual da AGT antes de produção. Taxa 0 (isenção)
    | está DESATIVADA por omissão: exige motivo de isenção (códigos M no SAF-T), ainda
    | por implementar — só a ativar depois disso (ver README).
    */
    'taxas_iva_permitidas' => array_map('floatval', array_filter(explode(',', (string) env('FATURACAO_TAXAS_IVA_PERMITIDAS') ?: '14'), 'strlen')),

    /*
    |--------------------------------------------------------------------------
    | Comunicação com a AGT (Facturação Electrónica)
    |--------------------------------------------------------------------------
    | `ativo` desligado: o módulo numera localmente (desenvolvimento). Ligado:
    | só se numera em séries autorizadas pela AGT e os pedidos usam Basic Auth
    | com as credenciais pedidas por e-mail a produtores.dfe.dcrr.agt@minfin.gov.ao
    | (nome e NIF da empresa produtora). Os endereços e caminhos são os da
    | documentação oficial da AGT e ficam configuráveis porque a documentação de
    | homologação tem inconsistências — confirma-os na homologação.
    */
    'agt' => [
        'ativo' => (bool) env('FATURACAO_AGT_ATIVO', false),
        'ambiente' => env('FATURACAO_AGT_AMBIENTE', 'homologacao'), // homologacao | producao
        'urls' => [
            'homologacao' => env('FATURACAO_AGT_URL_HOMOLOGACAO', 'https://sifphml.minfin.gov.ao/sigt/fe/v1'),
            'producao' => env('FATURACAO_AGT_URL_PRODUCAO', 'https://sifp.minfin.gov.ao/sigt/fe/v1'),
        ],
        'caminhos' => [
            'solicitar_serie' => '/solicitarSerie',
            'registar_factura' => '/registarFactura',
            'obter_estado' => '/obterEstado',
        ],
        'utilizador' => env('FATURACAO_AGT_UTILIZADOR'),
        'palavra_passe' => env('FATURACAO_AGT_PALAVRA_PASSE'),
        'timeout' => (int) env('FATURACAO_AGT_TIMEOUT', 20),
        'estabelecimento_padrao' => env('FATURACAO_AGT_ESTABELECIMENTO', 'SEDE'),
        'lote_maximo' => 30, // máx. de documentos por pedido registarFactura
        'max_tentativas' => (int) env('FATURACAO_AGT_MAX_TENTATIVAS', 5),
        'consulta_intervalo' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO', 60), // segundos entre consultas de estado
        'sem_resposta_horas' => (int) env('FATURACAO_AGT_SEM_RESPOSTA_HORAS', 24), // horas até um "enviado" sem estado final exigir intervenção
        'operation_type_padrao' => env('FATURACAO_AGT_OPERATION_TYPE', 'SG'), // tipo de operação das linhas (SG = serviço geral)
        'unidade_padrao' => 'UN',
        // Taxa de IVA (2 casas) => taxCode da AGT (NOR, INT, RED, OUT). Só as listadas são enviadas.
        'codigos_taxa' => ['14.00' => 'NOR'],
        'software' => [
            'product_id' => env('FATURACAO_AGT_PRODUCT_ID', 'Saas1'),
            'product_version' => env('FATURACAO_AGT_PRODUCT_VERSION', '1.0.0'),
            // O número de certificação é o 'saft_numero_certificado' (acima).
            'signature_version' => env('FATURACAO_AGT_SIGNATURE_VERSION'),
            'chave_privada_path' => env('FATURACAO_AGT_CHAVE_SOFTWARE') ?: storage_path('app/faturacao/chave-software.pem'),
        ],
    ],

];
