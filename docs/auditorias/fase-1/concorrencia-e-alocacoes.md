# Fase 1 · Bloco 1 — Concorrência financeira e alocações de recibo por linha

Base do código testado: commit `87c8543` da branch `plan/fase-1-integridade-critica-20261116` (PR #58). Os dois commits
seguintes (`456764c`, `c72dc12`) só alteram documentação e CI. Data: 2026-11-21.
Ambiente das medições: PHP 8.4.27, Laravel 13.42.0, PHPUnit 12.5.36, SQLite e MySQL 8.0.46.

## 1. Estado verificado antes de alterar

| Verificação | Resultado |
|---|---|
| Suite completa em SQLite | 1009 testes, 2905 asserções, 5 ignorados, 1 incompleto — OK |
| Suite em MySQL 8.0.46 (`--exclude-group=auditoria`) | 999 testes, 2884 asserções, 5 ignorados, 1 incompleto — OK |
| CI do commit (qualidade, testes-mysql, composer-audit) | verde em 2026-11-17 |

A descrição da PR #58 ainda diz que o job MySQL foi cancelado: está desactualizada.

## 2. Defeito confirmado: sobre-alocação sob concorrência real

**Causa.** `ReciboService`, `AbatimentoAdiantamentoService` e `NotaCreditoDebitoService` bloqueiam o documento
(`lockForUpdate`) e depois somam o que já foi recebido, abatido ou creditado com um `SELECT` normal. Em MySQL
com `REPEATABLE READ` (padrão) esse `SELECT` usa o instantâneo aberto na primeira leitura da transação, que
acontece **antes** de esperar pelo lock (`GuardaEmissao` lê a empresa). Não vê o que outra transação acabou de
confirmar, e cada processo conclui que o documento ainda tem saldo. Em SQLite não se reproduz (há um só escritor).

**Evidência** (6 processos PHP independentes, MySQL 8.0.46, `ConcorrenciaFinanceiraRealTest`):

| Fluxo | Limite correcto | Antes (REPEATABLE READ) | Depois (READ COMMITTED) |
|---|---|---|---|
| Recibos de 1140.00 sobre fatura de 1140.00 | 1 aceite | 6 aceites (6840.00 recebidos) | 1 aceite |
| Abatimentos de 500.00 sobre adiantamento recebido de 1140.00 | 2 aceites | 4 a 5 aceites (2000.00 a 2500.00) | 2 aceites |
| Notas de crédito de 1140.00 sobre fatura de 1140.00 | 1 aceite | 6 aceites | 1 aceite |

A CI não apanhava isto porque os testes existentes de concorrência real só cobriam emissão e reenvio de campanhas.

**Correcção.** `config/database.php`: `isolation_level` = `READ COMMITTED` nas ligações `mysql` e `mariadb`
(variável `DB_ISOLATION_LEVEL`; vazia = padrão do servidor). Com isto, depois do lock, cada leitura vê o que já
foi confirmado. Corrige de uma vez todos os fluxos "bloqueia e soma", presentes e futuros.

**Regressão.** `ConcorrenciaFinanceiraRealTest` (grupo `concorrencia-real`, corre no job `testes-mysql`).
Com `DB_ISOLATION_LEVEL='REPEATABLE READ'` os três testes falham (6 em vez de 1, 5 em vez de 2, 6 em vez de 1).

**Decisão a confirmar:** o isolamento passa a ser global na ligação MySQL. Alternativa mais localizada: leituras
bloqueantes (`lockForUpdate`) nas somas de cada serviço. Não foi escolhida porque deixa por proteger qualquer fluxo
novo com o mesmo padrão.

**Limite.** Só recibos, abatimentos e notas de crédito têm teste de concorrência real. Compras, Ateliê, Estúdio e
Subscrições beneficiam da correcção global mas não têm teste próprio.

## 3. Alocações de recibo por linha (fundação)

Implementa o ponto 1 e a base do ponto 2 das pendências da PR: a persistência de que linha de que fatura cada
recibo regulariza, segundo `decisao-alocacoes-paymentReceipt.md`.

- Tabela `recibo_alocacoes`: empresa, recibo, fatura, linha, `numero_linha_fiscal` (posição base 1 na fatura, pela
  ordem do `AgtDocumentoBuilder`), `sequencia` (posição no recibo), líquido, imposto e bruto em cêntimos, chave
  idempotente. FKs compostas (empresa+recibo, empresa+fatura, fatura+linha), unicidade por recibo+linha, triggers
  que recusam `UPDATE`/`DELETE`, bruto ≠ líquido+imposto, numeração a zero e recibo que já não é rascunho.
- Migration aditiva (só cria a tabela e dois índices únicos triviais, `(empresa_id, id)` e `(fatura_id, id)`; não toca em dados).
  O `down()` recusa-se a correr se já houver alocações e repõe o índice próprio da FK antes de remover o composto:
  em MySQL, ao criar o composto, o índice automático da FK é largado e a primeira versão do `down()` falhava (erro 1553).
- `ReciboAlocacaoService::registar()`: atómico, idempotente por chave, bloqueia recibo → faturas → linhas por ordem
  de id, recusa sobre-alocação por linha (líquido, imposto e bruto), faturas não emitidas ou de outro cliente,
  linhas de outra empresa, recibos que não sejam AR/RG ou já não sejam rascunho, e exige que a soma dos brutos
  iguale o valor do recibo. As somas usam leituras bloqueantes, por isso protegem mesmo sem READ COMMITTED.
- Modelo `ReciboAlocacao`: só leitura, imutável, com `TenantScope`.

Testes: `ReciboAlocacaoTest` (43 testes, SQLite e MySQL) e `ReciboAlocacaoConcorrenciaRealTest` (8 processos com a
mesma chave gravam uma vez; 6 recibos a disputar uma linha só deixam 2 passar).

**Não implementado, por falta de decisão ou evidência oficial:**
- Rateio de um pagamento parcial entre líquido e IVA: o serviço recebe os montantes do chamador.
- Leitura de `lineNo` da AGT (posição na fatura de origem ou sequência no recibo): guardam-se as duas.
- Alocações a linhas de notas de crédito/débito e disponível por linha depois de notas de crédito.
- Ligação ao `ReciboService`, ao `AgtDocumentoBuilder` e ao `FaturacaoGateway`.
- RG e AR continuam bloqueados. Nada neste bloco afirma conformidade ou homologação AGT.

## 4. Outras correcções

- CI: o passo "Auditoria de subscrições (MySQL)" não corria `test_servico_recusa_retoma_de_renovacao_de_subscricao_vencida`.
  Confirmado que passa em MySQL (10 testes OK); o commit `c72dc12` já o acrescentou.
- Removido o ficheiro `an test --filter=Faturacao` da raiz (cópia acidental do ecrã de ajuda do `less`).

## 5. Evidência depois das alterações

| Comando | Resultado |
|---|---|
| `phpunit` (SQLite, tudo) | 1057 testes, 2991 asserções, 10 ignorados (5 anteriores + 5 de concorrência real que exigem MySQL), 1 incompleto — OK |
| `phpunit --exclude-group=auditoria` (MySQL 8.0.46) | 1047 testes, 2970 asserções, 10 ignorados, 1 incompleto — OK |
| `migrate:fresh` + `phpunit --group=concorrencia-real` (MySQL, `SAAS1_CONCORRENCIA_REAL=1`) | 8 testes, 183 asserções — OK |
| `AuditoriaSubscricoesTest` completo (MySQL) | 10 testes, 30 asserções — OK |
| `migrate:fresh` → `migrate:rollback --step=1` → `migrate` com a BD vazia | MySQL 8.0.46 e SQLite OK (tabela, 3 triggers e índices repostos) |
| `migrate:rollback --step=1` com alocações gravadas (MySQL) | recusado com "Rollback recusado"; as 4 alocações ficam |
| `pint --test` nos 8 ficheiros PHP tocados | PASS |

Ficam por correr aqui: `composer audit` (sem acesso ao Packagist) e a CI do GitHub sobre estas alterações.
