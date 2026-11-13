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
        'FA' => 'Fatura de Adiantamento',
        'NC' => 'Nota de Crédito',
        'ND' => 'Nota de Débito',
        'RC' => 'Recibo',
        'RG' => 'Recibo de Regularização',
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

    'taxa_iva_geral' => (string) env('FATURACAO_TAXA_IVA_GERAL', '14'),

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
    | xmlns do elemento raiz do SAF-T (AO). Vazio por omissão: nesse caso usa-se o targetNamespace do
    | XSD oficial, se estiver presente. Só preenche se o confirmares no XSD/especificação da AGT.
    */
    'saft_namespace' => env('FATURACAO_SAFT_NAMESPACE') ?: null,

    /*
    | Nota de débito e IVA. Por omissão a nota de débito NÃO liquida imposto
    | (regime jurídico angolano, segundo a auditoria técnica — confirma com a
    | AGT/contabilista). Só passa a true se a tua análise jurídica disser o contrário.
    */
    'nota_debito_liquida_iva' => (bool) env('FATURACAO_ND_LIQUIDA_IVA', false),

    /*
    | Abatimento de adiantamentos (FA) em faturas finais (FT). Hoje é contabilidade interna:
    | não gera documento fiscal nem entra na comunicação AGT/SAF-T. Enquanto a representação
    | fiscal não estiver definida com a AGT e implementada, em produção a pré-validação do
    | SAF-T recusa períodos com abatimentos. NÃO ponhas true só para desbloquear a exportação:
    | true afirma que a representação fiscal existe (ver docs/homologacao-agt.md, ponto H3).
    */
    'abatimentos_adiantamento_confirmados' => (bool) env('FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS', false),

    /*
    | Taxas de IVA aceites na emissão (percentagens, separadas por vírgula no .env).
    | Confirma contra a tabela atual da AGT antes de produção. A taxa 0 (isenção)
    | está DESATIVADA por omissão: para a ativar junta-a (FATURACAO_TAXAS_IVA_PERMITIDAS=14,0)
    | e confirma a tabela 'motivos_isencao' abaixo — cada linha a 0% exige um motivo.
    */
    'taxas_iva_permitidas' => array_map('floatval', array_filter(explode(',', (string) env('FATURACAO_TAXAS_IVA_PERMITIDAS') ?: '14'), 'strlen')),

    /*
    |--------------------------------------------------------------------------
    | Motivos de isenção / não sujeição de IVA — Anexo 6.4 da AGT
    |--------------------------------------------------------------------------
    | Códigos e menções transcritos do portal oficial de Facturação Electrónica
    | da AGT (Anexos > Tabela de Isenções de IVA).
    |
    | tipo:
    | - ISE: IVA com taxCode ISE;
    | - NS: operação não sujeita (taxType NS);
    | - REGIME: código de enquadramento do regime, não aplicável como motivo
    |   individual de uma linha a 0% (M00).
    |
    | A lista oficial está disponível na interface, mas a comunicação à AGT
    | continua bloqueada até à confirmação operacional/homologação da tabela.
    */
    'motivos_isencao_confirmados' => (bool) env('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS', false),

    'motivos_isencao' => [
        'M10' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea a) do nº1 do artigo 12.º do CIVA', 'descricao' => 'A transmissão dos bens alimentares, conforme anexo I do presente código.'],
        'M11' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea b) do nº1 do artigo 12.º do CIVA', 'descricao' => 'As transmissões de medicamentos destinados exclusivamente a fins terapêuticos e profilácticos.'],
        'M12' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea c) do nº1 do artigo 12.º do CIVA', 'descricao' => 'As transmissões de cadeiras de rodas e veículos semelhantes, accionados manualmente ou por motor, para portadores de deficiência, aparelhos, máquinas de escrever com caracteres braille, impressoras para caracteres braille e os artefactos que se destinam a ser utilizados por invisuais ou a corrigir a audição.'],
        'M13' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea d) do nº1 do artigo 12.º do CIVA', 'descricao' => 'A transmissão de livros, incluindo em formato digital.'],
        'M14' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea e) do nº1 do artigo 12.º do CIVA', 'descricao' => 'A locação de bens imóveis destinados a fins habitacionais, designadamente prédios urbanos, fracções autónomas destes ou terrenos para construção, com excepção das prestações de serviços de alojamento efectuadas no âmbito da actividade hoteleira ou de outras com funções análogas.'],
        'M15' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea f) do nº1 do artigo 12.º do CIVA', 'descricao' => 'As operações sujeitas ao imposto de SISA, ainda que dele isentas.'],
        'M16' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea g) do nº1 do artigo 12.º do CIVA', 'descricao' => 'A exploração e a prática de jogos de fortuna ou azar e de diversão social, bem como as respectivas comissões e todas as operações relacionadas, quando as mesmas estejam sujeitas a Imposto Especial sobre o Jogos, nos termos da legislação aplicável.'],
        'M17' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea h) do nº1 do artigo 12.º do CIVA', 'descricao' => 'O transporte colectivo de passageiros.'],
        'M18' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea i) do nº1 do artigo 12.º do CIVA', 'descricao' => 'As operações de intermediação financeira, incluindo a locação financeira, exceptuando-se aquelas em que uma taxa, ou contraprestação, específica e predeterminada é cobrada pelo serviço.'],
        'M19' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea j) do nº1 do artigo 12.º do CIVA', 'descricao' => 'O seguro de saúde, bem como a prestação de seguros e resseguros do ramo vida.'],
        'M20' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea k) do nº1 do artigo 12.º do CIVA', 'descricao' => 'As transmissões de produtos petrolíferos conforme anexo II do presente código.'],
        'M21' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea l) do nº1 do artigo 12.º do CIVA', 'descricao' => 'As prestações de serviço que tenham por objecto o ensino, efectuadas por estabelecimentos integrados conforme definidos na Lei de Bases do Sistema de Educação e Ensino, bem como por estabelecimentos de Ensino Superior devidamente reconhecidos pelo Ministério de Tutela.'],
        'M22' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea m) do artigo 12.º do CIVA', 'descricao' => 'As prestações de serviço médico sanitário, efectuadas por estabelecimentos hospitalares, clínicas, dispensários e similares.'],
        'M23' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea n) do artigo 12.º do CIVA', 'descricao' => 'O transporte de doentes ou feridos em ambulâncias ou outros veículos apropriados efectuados por organismos devidamente autorizados.'],
        'M24' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea o) do artigo 12.º do CIVA', 'descricao' => 'Os equipamentos médicos para o exercício da actividade dos estabelecimentos de saúde.'],
        'M80' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea a) do nº1 do artigo 14.º', 'descricao' => 'As importações definitivas de bens cuja transmissão no território nacional seja isenta de imposto.'],
        'M81' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea b) do nº1 do artigo 14.º', 'descricao' => 'As importações de ouro, moedas ou notas de banco, efectuadas pelo Banco Nacional de Angola.'],
        'M82' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea c) do nº1 do artigo 14.º', 'descricao' => 'A importação de bens destinados a ofertas para atenuar os efeitos das calamidades naturais, tais como cheias, tempestades, secas, ciclones, sismos, terramotos e outros de idêntica natureza, desde que devidamente autorizado pelo Titular do Poder Executivo.'],
        'M83' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea d) do nº1 do artigo 14.º', 'descricao' => 'A importação de mercadorias ou equipamentos destinados exclusivos e directamente à execução das operações petrolíferas e mineiras nos termos da Lei que estabelece o Regime Aduaneiro do Sector Petrolífero e do Código Mineiro, respectivamente.'],
        'M84' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea e) do nº1 do artigo 14.º', 'descricao' => 'Importação de moeda estrangeira efectuada pelas instituições financeiras bancárias, nos termos definidos pelo Banco Nacional de Angola.'],
        'M85' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea a) do nº2 do artigo 14.º', 'descricao' => 'No âmbito de tratados e acordos internacionais de que a República de Angola seja parte, nos termos previstos nesses tratados e acordos.'],
        'M86' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea b) do nº2 do artigo 14.º', 'descricao' => 'No âmbito de relações diplomáticas e consulares, quando a isenção resulte de tratados e acordos internacionais celebrados pela República de Angola.'],
        'M30' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea a) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens expedidos ou transportados com destino ao estrangeiro pelo vendedor ou por um terceiro por conta deste.'],
        'M31' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea b) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens de abastecimento postos a bordo das embarcações que efectuem navegação marítima em alto mar e que assegurem o transporte remunerado de passageiros ou o exercício de uma actividade comercial, industrial ou de pesca.'],
        'M32' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea c) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens de abastecimento postos a bordo das aeronaves utilizadas por companhias de navegação aérea que se dediquem principalmente ao tráfego internacional e que assegurem o transporte remunerado de passageiros, ou o exercício de uma actividade comercial ou industrial.'],
        'M33' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea d) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens de abastecimento postos a bordo das embarcações de salvamento, assistência marítima, pesca costeira e embarcações de guerra, quando deixem o país com destino a um porto ou ancoradouro situado no estrangeiro.'],
        'M34' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea e) do artigo 15.º do CIVA', 'descricao' => 'As transmissões, transformações, reparações, manutenção, frete e aluguer, incluindo a locação financeira, de embarcações e aeronaves afectas às companhias de navegação aérea e marítima que se dediquem principalmente ao tráfego internacional, assim como as transmissões de bens de abastecimento postos a bordo das mesmas e as prestações de serviços efectuadas com vista à satisfação das suas necessidades directas e da respectiva carga.'],
        'M35' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea f) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens efectuadas no âmbito de relações diplomáticas e consulares cuja isenção resulte de acordos e convénios internacionais celebrados por Angola.'],
        'M36' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea g) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens destinados a organismos internacionais reconhecidos por Angola ou a membros dos mesmos organismos, nos limites e com as condições fixadas em acordos e convénios internacionais celebrados por Angola.'],
        'M37' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea h) do artigo 15.º do CIVA', 'descricao' => 'As transmissões de bens efectuadas no âmbito de tratados e acordos internacionais de que a República de Angola seja parte, quando a isenção resulte desses mesmos tratados e acordos.'],
        'M38' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea i) do artigo 15.º do CIVA', 'descricao' => 'O transporte de pessoas provenientes ou com destino ao estrangeiro.'],
        'M90' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea a) do nº1 do artigo 16.º', 'descricao' => 'As importações de bens que, sob controlo aduaneiro e de acordo com as disposições aduaneiras especificamente aplicáveis, sejam postas nos regimes de zona franca, que sejam introduzidos em armazéns de regimes aduaneiros ou lojas francas, enquanto permanecerem sob tais regimes.'],
        'M91' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea b) do nº1 do artigo 16.º', 'descricao' => 'As transmissões de bens que sejam expedidos ou transportados para as zonas ou depósitos mencionados na alínea anterior, bem como as prestações de serviços directamente conexas com essas transmissões.'],
        'M92' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea c) do nº1 do artigo 16.º', 'descricao' => 'As transmissões de bens que se efectuem nos regimes a que se refere a alínea a), assim como as prestações de serviços directamente conexas com essas transmissões, enquanto os bens permanecerem naquelas situações.'],
        'M93' => ['tipo' => 'ISE', 'mencao' => 'Isento nos termos da alínea d) do nº1 do artigo 16.º', 'descricao' => 'As transmissões de bens que se encontrem nos regimes de trânsito, draubaque ou importação temporária e as prestações de serviços directamente conexas com essas operações, enquanto os mesmos forem considerados abrangidos por aqueles regimes.'],
        'M00' => ['tipo' => 'REGIME', 'mencao' => 'IVA– Regime Simplificado', 'descricao' => 'Código de enquadramento do regime simplificado; não é motivo de isenção individual de uma linha a 0%.'],
        'M02' => ['tipo' => 'NS', 'mencao' => 'Transmissão de bens e serviço não sujeita', 'descricao' => 'Operação não sujeita a IVA.'],
        'M04' => ['tipo' => 'NS', 'mencao' => 'IVA – Regime de Exclusão', 'descricao' => 'Enquadramento no Regime de Exclusão do IVA.'],
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
            // Obrigatória em registarFactura/obterEstado; corresponde à chave do software registada na AGT.
            'signature_version' => env('FATURACAO_AGT_SIGNATURE_VERSION'),
            'chave_privada_path' => env('FATURACAO_AGT_CHAVE_SOFTWARE') ?: storage_path('app/faturacao/chave-software.pem'),
        ],
    ],

];
