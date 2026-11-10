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

## 3. Regra de comunicação

- Nunca afirmar «homologado» ou «conforme à AGT» enquanto H1, H3, H6 e H7 estiverem abertos.
- Cada ponto fechado regista aqui a evidência (data, ficheiro XSD/versão, resposta da AGT) e o commit.
