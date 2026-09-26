<?php

return [

    'name' => 'Faturacao',

    /*
    |--------------------------------------------------------------------------
    | Chave RSA do produtor de software
    |--------------------------------------------------------------------------
    |
    | Segundo o mecanismo descrito pela AGT (Ofício Circulado 50001/2013,
    | com base na Portaria n.º 363/2010), cada documento fiscal é assinado
    | com a chave PRIVADA do produtor do software — não da empresa cliente.
    | A chave pública correspondente deve ser comunicada à AGT (modelo 24)
    | antes de qualquer documento assinado com ela ser válido.
    |
    | NUNCA committes a chave privada para o Git. Gera-a com:
    |   openssl genrsa -out storage/app/faturacao/chave-privada.pem 2048
    |   openssl rsa -in storage/app/faturacao/chave-privada.pem -pubout -out storage/app/faturacao/chave-publica.pem
    | e acrescenta storage/app/faturacao/ ao .gitignore.
    |
    */
    'chave_privada_path' => env('FATURACAO_CHAVE_PRIVADA_PATH', storage_path('app/faturacao/chave-privada.pem')),

    /*
    | Versão da chave em uso — a AGT exige que cada documento registe qual
    | a versão da chave que o assinou (nº inteiro sequencial), para permitir
    | rotação de chaves sem invalidar documentos antigos.
    */
    'chave_versao' => (int) env('FATURACAO_CHAVE_VERSAO', 1),

    /*
    |--------------------------------------------------------------------------
    | Algoritmo de assinatura
    |--------------------------------------------------------------------------
    | RSA com SHA-1 é o que a família de especificações SAF-T lusófona
    | historicamente usa (à qual o mecanismo da AGT está claramente
    | alinhado). Confirma este detalhe contra a documentação técnica atual
    | da AGT antes de produção — ver README.
    */
    'algoritmo_assinatura' => OPENSSL_ALGO_SHA1,

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

];
