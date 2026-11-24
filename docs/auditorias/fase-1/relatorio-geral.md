# Relatório geral — Auditoria da Fase 1 (SaaS1)

**Estado em 2026-11-18** · PR #58 (Draft) · branch `plan/fase-1-integridade-critica-20261116` · commit de partida `87c8543`

**Decisão provisória: FASE 1 PARCIALMENTE CONCLUÍDA.** Existem bloqueios que dependem de documentação ou decisão oficial da AGT e trabalho por fazer. Esta não é a decisão final.

«Concluída» só com evidência reproduzível. A auditoria estática não executou testes: os resultados citados vêm da CI do GitHub.

## Matriz dos critérios de aceitação

| # | Critério | Estado | Evidência / motivo |
|---|---|---|---|
| 1 | Arquitectura auditada | Parcial | Auditoria estática de SAF-T, FKs e CI; `arquitectura.md` por escrever |
| 2 | Problemas estruturais resolvidos | Parcial | PR #58: FKs compostas multiempresa (abatimentos, compras), guardas de tenant, triggers de coerência; restantes em `pendencias-e-decisoes.md` |
| 3 | Motor central de faturação consistente | Parcial | `resumoDaFatura()` é a fonte do Estúdio; Atelier bloqueia pagamento final com sinal legado; fluxo FA→FT fiscal por concluir |
| 4 | NC respeitam os limites | Parcial | Teste de NC parciais acumuladas acrescentado na PR #58; teste de concorrência real de NC em `ConcorrenciaFinanceiraRealTest` (6 NC de 1140.00 sobre uma fatura de 1140.00: 1 aceite depois da correção de isolamento; antes eram 6). NC por linha por tratar (P-13) |
| 5 | Adiantamentos correctamente implementados | Bloqueada | Ciclo interno FA→abatimento existe; falta o documento de recebimento fiscal da FA (P-02) |
| 6 | Recebimentos correctamente alocados | Bloqueada | RG bloqueado: a persistência de alocações por linha existe (`recibo_alocacoes`, `ReciboAlocacaoService`) mas ainda não está ligada ao recibo nem ao `paymentReceipt` (P-02, P-03, P-13) |
| 7 | Abatimentos reconciliados | Parcial | FK composta e testes; SAF-T com abatimentos recusado em produção por configuração |
| 8 | SAF-T validado contra a especificação | Não comprovada | Exportador provisório; divergências em `saft-ao.md`; XSD real nunca usado em teste |
| 9 | Isolamento multiempresa comprovado | Parcial | Testes na PR #58; faltam testes IDOR HTTP por módulo e de jobs/commands |
| 10 | Migrations seguras | Parcial | As da PR #58 interrompem com dados inválidos; rollback e dados históricos não auditados |
| 11 | Concorrência testada em MySQL | Parcial | O grupo `concorrencia-real` (executado na CI com `SAAS1_CONCORRENCIA_REAL=1`) tinha 3 testes (8 emissões simultâneas sem lacunas e com cadeia de hash contínua, 6 emissões simultâneas da mesma fatura e 8 reenvios simultâneos de campanha) e passa a ter 19: acrescenta recibos, abatimentos e NC simultâneos (`ConcorrenciaFinanceiraRealTest`), alocações de recibo (`ReciboAlocacaoConcorrenciaRealTest`), o limite mensal de faturas do plano (`LimiteMensalConcorrenciaRealTest`), sinal e saldo final de projectos do Estúdio (`FaturacaoEstudioConcorrenciaRealTest`), sinal, fatura final e pagamento final de pedidos do Ateliê (`PedidoFaturacaoConcorrenciaRealTest`) a confirmação de pagamentos de subscrições (`PagamentoConcorrenciaRealTest`) e 3 cenários de carga mista com recibos, NC e abatimentos em simultâneo sobre os mesmos documentos (`CargaMistaConcorrenciaRealTest`: sem deadlocks nem saldos negativos sem NC, e sem sobreposição entre abatimentos e NC do mesmo adiantamento). Com REPEATABLE READ falham 7 (6 recibos de 1140.00 aceites numa fatura de 1140.00, abatimentos, NC, 6 faturas emitidas com limite mensal de 2 e a carga mista, que chegou a deixar a fatura com saldo de -12600.00); corrigido com `isolation_level` READ COMMITTED (P-12). Sem teste com processos reais para o check-out de sessões do Estúdio, a criação de utilizadores com limite do plano e os pagamentos de compras (P-11) |
| 12 | Ateliê fiscalmente integrado | Bloqueada | P-07 |
| 13 | Estúdio fiscalmente integrado | Parcial | Saldos lidos do gateway; sinal ainda como FT (P-08) |
| 14 | Subscrições consistentes | Parcial | 10 testes de auditoria na CI; limite mensal de faturas e confirmação de pagamentos validados com processos reais em MySQL (`concorrencia-e-alocacoes.md`); P-06 por decidir |
| 15 | Regressão aprovada | Parcial | CI verde em `87c8543`; contagem por registar na CI (P-10). Contagem local em `87c8543`: SQLite 1009 e MySQL 999 testes, todos OK (`concorrencia-e-alocacoes.md`) |
| 16 | CI verde | Cumprida em `87c8543` | `qualidade`, `testes-mysql`, `composer-audit` = success (2026-11-17); reavaliar a cada commit |
| 17 | Sem bloqueadores críticos/altos sem decisão | Não cumprida | Ver `pendencias-e-decisoes.md` |

## Documentos desta auditoria

`referencias-oficiais.md`, `saft-ao.md`, `pendencias-e-decisoes.md`, `decisao-alocacoes-paymentReceipt.md`, `concorrencia-e-alocacoes.md`. Os restantes da lista da missão ficam por escrever à medida que cada bloco for validado; não se criam ficheiros vazios.
