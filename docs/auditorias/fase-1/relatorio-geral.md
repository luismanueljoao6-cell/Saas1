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
| 4 | NC respeitam os limites | Parcial | Teste de NC parciais acumuladas acrescentado na PR #58; sem teste de concorrência real de NC (P-11) |
| 5 | Adiantamentos correctamente implementados | Bloqueada | Ciclo interno FA→abatimento existe; falta o documento de recebimento fiscal da FA (P-02) |
| 6 | Recebimentos correctamente alocados | Bloqueada | RG bloqueado até existirem alocações por linha (P-02, P-03) |
| 7 | Abatimentos reconciliados | Parcial | FK composta e testes; SAF-T com abatimentos recusado em produção por configuração |
| 8 | SAF-T validado contra a especificação | Não comprovada | Exportador provisório; divergências em `saft-ao.md`; XSD real nunca usado em teste |
| 9 | Isolamento multiempresa comprovado | Parcial | Testes na PR #58; faltam testes IDOR HTTP por módulo e de jobs/commands |
| 10 | Migrations seguras | Parcial | As da PR #58 interrompem com dados inválidos; rollback e dados históricos não auditados |
| 11 | Concorrência testada em MySQL | Parcial | O grupo `concorrencia-real` (executado na CI com `SAAS1_CONCORRENCIA_REAL=1`) tem 3 testes: 8 emissões simultâneas sem lacunas e com cadeia de hash contínua, 6 emissões simultâneas da mesma fatura e 8 reenvios simultâneos de campanha. Sem teste com processos reais para NC, recebimentos, adiantamentos/abatimentos, pagamentos, subscrições, pedidos e faturação de projectos (P-11) |
| 12 | Ateliê fiscalmente integrado | Bloqueada | P-07 |
| 13 | Estúdio fiscalmente integrado | Parcial | Saldos lidos do gateway; sinal ainda como FT (P-08) |
| 14 | Subscrições consistentes | Parcial | 10 testes de auditoria na CI; P-06 por decidir |
| 15 | Regressão aprovada | Parcial | CI verde em `87c8543`; contagem por registar (P-10) |
| 16 | CI verde | Cumprida em `87c8543` | `qualidade`, `testes-mysql`, `composer-audit` = success (2026-11-17); reavaliar a cada commit |
| 17 | Sem bloqueadores críticos/altos sem decisão | Não cumprida | Ver `pendencias-e-decisoes.md` |

## Documentos desta auditoria

`referencias-oficiais.md`, `saft-ao.md`, `pendencias-e-decisoes.md`, `decisao-alocacoes-paymentReceipt.md`. Os restantes da lista da missão ficam por escrever à medida que cada bloco for validado; não se criam ficheiros vazios.
