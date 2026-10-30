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

    // Chave pública de recurso do faturacao:verificar-cadeia (quando a empresa não tem {NIF}.pem neste servidor).
    'chave_publica_path' => env('FATURACAO_CHAVE_PUBLICA_PATH') ?: storage_path('app/faturacao/chave-publica.pem'),

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
    /*
    | Meios de pagamento aceites nos recibos emitidos pela interface (código => rótulo).
    | São os mesmos que o Atelier e o Estúdio já usam.
    */
    'meios_pagamento' => [
        'dinheiro' => 'Dinheiro',
        'transferencia' => 'Transferência bancária',
        'multicaixa' => 'Multicaixa',
        'outro' => 'Outro',
    ],

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
    | XSD oficial do SAF-T (AO), usado para validar cada ficheiro gerado. Obtém-no
    | nas especificações oficiais da AGT e guarda-o neste caminho. Sem ele, em
    | produção, nenhum SAF-T é marcado como pronto para exportação fiscal.
    */
    'saft_xsd_path' => env('FATURACAO_SAFT_XSD_PATH') ?: storage_path('app/faturacao/saft/SAF-T-AO.xsd'),

    /*
    | Nota de débito e IVA. Por omissão a nota de débito NÃO liquida imposto
    | (regime jurídico angolano, segundo a auditoria técnica — confirma com a
    | AGT/contabilista). Só passa a true se a tua análise jurídica disser o contrário.
    */
    'nota_debito_liquida_iva' => (bool) env('FATURACAO_ND_LIQUIDA_IVA', false),

    /*
    | Taxas de IVA aceites na emissão (percentagens, separadas por vírgula no .env).
    | Confirma contra a tabela atual da AGT antes de produção. A taxa 0 (isenção)
    | está DESATIVADA por omissão: para a ativar junta-a (FATURACAO_TAXAS_IVA_PERMITIDAS=14,0)
    | e confirma a tabela 'motivos_isencao' abaixo — cada linha a 0% exige um motivo.
    */
    'taxas_iva_permitidas' => array_map('floatval', array_filter(explode(',', (string) env('FATURACAO_TAXAS_IVA_PERMITIDAS') ?: '14'), 'strlen')),

    /*
    |--------------------------------------------------------------------------
    | Motivos de isenção / não sujeição de IVA (taxExemptionCode)
    |--------------------------------------------------------------------------
    | Uma linha a 0% só é aceite com um motivo desta tabela, e o código segue
    | para a AGT em `taxExemptionCode` (3 caracteres, Anexo 6.4 do Decreto
    | Executivo n.º 683/25). 'tipo': ISE => taxType IVA + taxCode ISE (isento);
    | NS => taxType NS (não sujeito).
    |
    | ATENÇÃO — tabela POR CONFIRMAR: é a transcrição de um terceiro (WISEDAT,
    | set/2025) da tabela angolana de motivos de isenção; não é a cópia oficial
    | do Anexo 6.4. Compara-a com o anexo oficial, corrige o que for preciso e só
    | então define FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true. Enquanto isso for
    | false, com a AGT ativa as isenções são recusadas (nenhum código por
    | confirmar segue para a AGT).
    | (M00 — Regime Simplificado — não é um motivo de isenção e fica de fora.)
    */
    'motivos_isencao_confirmados' => (bool) env('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS', false),

    'motivos_isencao' => [
        'M02' => ['tipo' => 'NS', 'descricao' => 'Transmissão de bens e serviço não sujeita'],
        'M04' => ['tipo' => 'NS', 'descricao' => 'IVA – Regime de Exclusão (Lei n.º 42/20)'],
        'M10' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 12.º do CIVA (bens alimentares)'],
        'M11' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 12.º do CIVA (medicamentos)'],
        'M12' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do n.º 1 do artigo 12.º do CIVA'],
        'M13' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do n.º 1 do artigo 12.º do CIVA (livros)'],
        'M14' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do n.º 1 do artigo 12.º do CIVA (locação de imóveis habitacionais)'],
        'M15' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea f) do n.º 1 do artigo 12.º do CIVA'],
        'M16' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea g) do n.º 1 do artigo 12.º do CIVA (jogos)'],
        'M17' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea h) do n.º 1 do artigo 12.º do CIVA (transporte colectivo de passageiros)'],
        'M18' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea i) do n.º 1 do artigo 12.º do CIVA (intermediação financeira)'],
        'M19' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea j) do n.º 1 do artigo 12.º do CIVA (seguros)'],
        'M20' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea k) do n.º 1 do artigo 12.º do CIVA (produtos petrolíferos)'],
        'M21' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea l) do n.º 1 do artigo 12.º do CIVA (ensino)'],
        'M22' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea m) do artigo 12.º do CIVA (serviços médico-sanitários)'],
        'M23' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea n) do artigo 12.º do CIVA (transporte de doentes)'],
        'M24' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea o) do artigo 12.º do CIVA (equipamentos médicos)'],
        'M30' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do artigo 15.º do CIVA (exportações)'],
        'M31' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do artigo 15.º do CIVA'],
        'M32' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do artigo 15.º do CIVA'],
        'M33' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do artigo 15.º do CIVA'],
        'M34' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do artigo 15.º do CIVA'],
        'M35' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea f) do artigo 15.º do CIVA'],
        'M36' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea g) do artigo 15.º do CIVA'],
        'M37' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea h) do artigo 15.º do CIVA'],
        'M38' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea i) do artigo 15.º do CIVA'],
        'M80' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 14.º do CIVA (importações)'],
        'M81' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do n.º 1 do artigo 14.º do CIVA'],
        'M82' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do n.º 1 do artigo 14.º do CIVA'],
        'M83' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do n.º 1 do artigo 14.º do CIVA'],
        'M84' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do n.º 1 do artigo 14.º do CIVA'],
        'M85' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 2 do artigo 14.º do CIVA'],
        'M86' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do n.º 2 do artigo 14.º do CIVA'],
        'M90' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 16.º do CIVA (regimes aduaneiros especiais)'],
        'M91' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do n.º 1 do artigo 16.º do CIVA'],
        'M92' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do n.º 1 do artigo 16.º do CIVA'],
        'M93' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do n.º 1 do artigo 16.º do CIVA'],
        'M94' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do n.º 1 do artigo 16.º do CIVA'],
    ],

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
        // Nota de débito (não liquida imposto): por omissão segue SEM bloco "taxes". Se definires aqui um
        // código de não sujeição (tipo NS da tabela motivos_isencao) e confirmares a tabela, segue com
        // taxType NS + este taxExemptionCode. Confirmar em homologação qual a AGT aceita.
        'nd_codigo_nao_sujeicao' => env('FATURACAO_AGT_ND_CODIGO_NS') ?: null,
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
