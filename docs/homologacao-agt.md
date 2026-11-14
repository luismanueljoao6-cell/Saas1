# Homologação fiscal AGT — estado e evidências em falta

Este documento separa **o que os testes automáticos provam** do que **só a AGT, o XSD
oficial ou uma certificação podem provar**. Testes internos verdes (CI, PR #33) **não são
homologação fiscal** e não devem ser apresentados como tal.

## 1. Provado por testes automáticos (coerência interna)

| Garantia | Onde |
|---|---|
| Valores monetários sem `float` (`Dinheiro`) | `Support/Dinheiro` e testes de linhas |
| Numeração sem lacunas e cadeia de hash por série (FT, NC/ND, recibos) | `SafTPreValidacao`, `CadeiaFiscalTest`, `SafTPreValidacaoTest`, `SafTRecibosTest` |
| Totais do documento = soma das linhas; IVA recalculado | `SafTPreValidacao::verificarDocumento` |
| Recibos exportados em `Payments`, ligados à fatura de origem, sem exceder o total | `SafTRecibosTest` |
| Abatimento de adiantamento idempotente, concorrente e isolado por empresa | `AbatimentoAdiantamentoService`, `AdiantamentoNotaCreditoTest`, `FluxoFiscalIntegradoTest` |
| NC sobre FA nunca invade a parte já abatida; anulação total recusada se houver abatimentos | `AdiantamentoNotaCreditoTest` |
| Ciclo FA → recibo → abatimento → FT → recibo → NC → SAF-T reconcilia (saldos e totais) | `FluxoFiscalIntegradoTest` |
| SAF-T e abatimentos isolados por empresa | `FluxoFiscalIntegradoTest` |
| Exportação SAF-T bloqueada em produção sem número de certificação | `SafTExportService::exportacaoBloqueada` |
| Imutabilidade (triggers) e FK `RESTRICT` nos abatimentos | migration `2026_11_10_000024` |

## 2. Em aberto — exige evidência externa

| # | Ponto | O que falta | Configuração / código afetado |
|---|---|---|---|
| H1 | Estrutura do SAF-T (AO) | Validar um ficheiro real contra o **XSD oficial** da AGT (inclui `Payments`, hoje provisório) | `faturacao.saft_xsd_path`, `SafTValidadorXsd`, `GerarSafTJob` |
| H2 | Namespace do ficheiro | Confirmar o `targetNamespace` oficial | `faturacao.saft_namespace` |
| H3 | Adiantamentos (FA) e abatimentos | Definir como o abatimento aparece na FT, na comunicação AGT e no SAF-T | `AbatimentoAdiantamentoService` (teste `…aguarda_a_especificacao_da_agt`) |
| H4 | Motivos de isenção | Confirmar a tabela contra o anexo oficial | `faturacao.motivos_isencao`, `FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS` |
| H5 | Códigos de taxa SAF-T | Confirmar os códigos por taxa | `faturacao.agt.codigos_taxa` |
| H6 | Certificação do software | Obter e configurar o número de certificado | `faturacao.saft_numero_certificado` |
| H7 | Comunicação com a AGT | Ensaio completo no ambiente de **homologação** da AGT (séries, submissão, correção de rejeitados) | `faturacao.agt.*`, `AgtSubmissaoService`, `AgtSerieService` |
| H8 | Base de dados real | Correr a suite e as migrations (instalação limpa, upgrade, rollback) em **MySQL** com dados de teste | CI MySQL; `adiantamento_abatimentos` (triggers) |
| H9 | Integridade entre empresas na BD | Hoje garantida pelo serviço; avaliar chave composta (empresa, fatura) nas FKs dos abatimentos | `adiantamento_abatimentos` |
| H10 | Âmbito e ciclo de vida da chave de assinatura | Confirmar na especificação vigente se a chave é do produtor do software ou da empresa emitente; confirmar versão, comunicação e rotação. A implementação arquiva chaves públicas por empresa/versão, mas não presume que esse âmbito esteja homologado | `AssinaturaFiscalService`, `faturacao_chaves_publicas`, `FATURACAO_CHAVE_VERSAO` |

## 3. Regra de comunicação

- Nunca afirmar «homologado» ou «conforme à AGT» enquanto H1, H3, H6 e H7 estiverem abertos.
- Cada ponto fechado regista aqui a evidência (data, ficheiro XSD/versão, resposta da AGT) e o commit.

## 4. H3 — salvaguarda ativa (adiantamentos e abatimentos)

- Os abatimentos continuam a ser **contabilidade interna**: não geram documento fiscal nem entram na comunicação AGT/SAF-T.
- A pré-validação do SAF-T reconcilia cada abatimento (mesma empresa, FA→FT, mesmo cliente, totais) e, **em produção**,
  recusa períodos com abatimentos enquanto `FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS` for `false` (por omissão).
- `true` afirma que a representação fiscal está definida **e implementada**. Só se muda depois de fechar o ponto H3
  com a especificação da AGT; nunca para desbloquear a exportação.
- Testes: `AbatimentoReconciliacaoSafTTest`, `AbatimentoIdempotenciaConcorrenteTest`.

## 5. H10 — arquivo de chaves públicas e documentos históricos

- A implementação guarda a chave pública e o fingerprint por combinação `empresa_id + versao`. O arquivo é imutável por versão: tentar reutilizar a mesma versão com outra chave interrompe a emissão.
- Antes da submissão à AGT e da publicação do SAF-T, o JWS é verificado com a chave pública arquivada para a empresa e a versão exacta do documento. O payload assinado também é comparado com os dados fiscais persistidos. Não se usa a chave actualmente instalada para validar automaticamente documentos antigos.
- Documentos emitidos antes desta migração podem não ter chave pública arquivada. Esses documentos ficam bloqueados para submissão até a chave original ser recuperada e associada à versão correcta; não se deve preencher o histórico com a chave actual por conveniência.
- **Pendente de confirmação externa:** o âmbito jurídico/técnico da chave (produtor do software vs. empresa emitente), a forma de comunicação à AGT e o processo de rotação têm de ser confirmados na documentação técnica vigente e em homologação. A implementação técnica não constitui prova de aceitação pela AGT. Os testes novos de assinatura e rotação aguardam a execução completa da CI.
