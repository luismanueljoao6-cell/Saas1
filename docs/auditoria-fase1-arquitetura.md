# Auditoria da Fase 1 — Arquitetura e organização estrutural

**Método:** leitura estática de `FaturacaoGateway`, `PedidoFaturacaoService`, `FaturacaoEstudioService`, `CampanhaMarketingService`,
`CrmService`, `StockService`, `ArmazemStockService`, `CompraService`, `FaturaService`, `OrcamentoController`, `BelongsToTenant`,
`TenantManager`, `GuardaEmissao`, `Dinheiro`, os `module.json` e as migrations do Core e da Faturação. Testes: 945 passam (5 saltados, 1 incompleto).
**Este documento não afirma conformidade fiscal.**

Estados: **CORRIGIDO** (neste PR) · **DECISÃO** (precisa de escolha de desenho ou de migração, PR próprio) · **FASE n** (pertence a outra fase) · **NÃO ALTERAR**.

| ID | Prio | Achado | Ficheiros | Estado |
|---|---|---|---|---|
| F1-01 | P1 | Dois livros de stock paralelos e sem ligação: `stock_saldos` (Faturação, por empresa e produto) e `armazem_stock_saldos` (Armazém, por armazém e produto). Proposta: livro único na Faturação com dimensão de armazém e `StockGateway` | `StockService`, `ArmazemStockService`, migration `..._000028` | DECISÃO |
| F1-02 | P1 | A emissão de FT falha por stock insuficiente, dentro da transação fiscal. Proposta: política de stock negativo configurável por empresa, com alerta | `FaturaService::emitir`, `StockService` | DECISÃO |
| F1-03 | P1 | O sinal é tratado de forma diferente: o Atelier regista um recibo sem fatura, o Estúdio emite uma FT (bloqueada com a AGT ativa). O gateway já tem `criarRascunhoAdiantamento` (FA) | `PedidoFaturacaoService`, `FaturacaoEstudioService`, `FaturacaoGateway` | DECISÃO (fiscal, Fases 4 e 6) |
| F1-04 | P1 | Consentimento de marketing em dois modelos: `ClienteCrm` com data e revogação (Atelier) contra booleano em `clientes` (Estúdio). Proposta: tabela única no Core, com migração | `CrmService`, `CampanhaMarketingService` | DECISÃO |
| F1-05 | P2 | Módulos importam internos da Faturação (`Models`, `Dinheiro`). O Atelier lê `Fatura` com `withoutGlobalScopes()`, mas com filtro explícito por empresa (isolamento verificado) | `PedidoFaturacaoService`, `FaturacaoEstudioService`, `ArmazemStockService` | DECISÃO (alargar o gateway; coordenar com a Fase 3) |
| F1-06 | P2 | Dependências entre módulos só em texto; os `module.json` não tinham `requires` | `Modules/*/module.json` | CORRIGIDO |
| F1-07 | P2 | O Estúdio calculava percentagens e minutos×preço à mão, sem proteção de overflow | `FaturacaoEstudioService`, `Dinheiro` | CORRIGIDO (`Dinheiro::percentagem`, `Dinheiro::porMinutos`) |
| F1-08 | P2 | O Estúdio lê `config('faturacao.taxa_iva_geral')` diretamente | `FaturacaoEstudioService` | DECISÃO (gateway) |
| F1-09 | P2 | A guarda de tenant tinha três cópias | `GuardaEmissao`, `StockService`, `ArmazemStockService` | CORRIGIDO (`TenantManager::garantirEmpresa`) |
| F1-10 | P2 | Aritmética decimal de quantidades duplicada (`emMilhares`, `formatar`) | `StockService`, `ArmazemStockService` | DECISÃO (resolve-se com F1-01) |
| F1-11 | P2 | Validação de linhas duplicada e dados dos ecrãs repetidos no controller | `OrcamentoController` | CORRIGIDO (`GuardarOrcamentoRequest`, `dadosFormulario`). Resta `abort_if` repetido, `withoutGlobalScopes` + `where` (Fase 2) e filtros no controller |
| F1-12 | P2 | O Orçamento guarda as linhas em JSON; Fatura e Nota usam tabelas de linhas | `Orcamento` | DECISÃO |
| F1-13 | P2 | Pagamentos de compras (`compra_pagamentos`) fora dos recibos e da tesouraria | `CompraService` | FASE 7 |
| F1-14 | P2 | Cada movimento de stock bloqueia a empresa inteira | `ArmazemStockService::prepararOperacao` | FASE 8 |
| F1-15 | P3 | PHPDoc de `FaturaService` com `float` | `FaturaService` | CORRIGIDO |
| F1-16 | P3 | Verificação de produto ativo repetida no Armazém | `ArmazemStockService` | CORRIGIDO no PR #48 |
| F1-17 | P3 | Número de compra aleatório (`CMP-` + data + 4 letras), fora do `NumeracaoService` | `CompraService` | DECISÃO |
| F1-18 | P3 | `StockService::guardarMovimento` repete validações já feitas | `StockService` | DECISÃO (remove-se com F1-01) |
| F1-19 | P3 | Migration `2026_11_14_000003` fora de sequência | migrations da Faturação | NÃO ALTERAR (já pode ter corrido) |
| F1-20 | P3 | Comentário de `TenantManager::semTenant` descrevia mal o uso | `TenantManager` | CORRIGIDO |
| F1-21 | P3 | Regra de aniversário a 29/02 implementada duas vezes (SQL no Atelier, PHP no Estúdio) | `CrmService`, `Support\Aniversario` | DECISÃO |
| F1-22 | P0 (Fase 2) | `BelongsToTenant`: sem tenant ativo, `updating` e `deleting` não bloqueiam e `creating` aceita qualquer `empresa_id` | `BelongsToTenant` | FASE 2 |

## Fora do âmbito desta passagem
Interior das Subscrições, models e middlewares do Core, FKs das migrations, controllers do Atelier e do Estúdio, e funcionalidades sem acesso na interface.
