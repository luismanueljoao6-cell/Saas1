# Auditoria — Fase 3: núcleo central da Faturação

Âmbito: documentos fiscais e comerciais, numeração, impostos, recibos, notas, contas correntes e a fronteira
(`FaturacaoGateway`) que os módulos verticais usam. Esta auditoria é técnica; **não declara conformidade fiscal**.

## Verificado e correto

- Todos os `withoutGlobalScopes()` de `Modules/Faturacao/app` (62 ocorrências) filtram por `empresa_id` nas linhas seguintes.
- Nenhum módulo vertical cria `Fatura`, `Recibo` ou `NotaCreditoDebito` diretamente nem tem numeração própria.
- Controllers, comandos e jobs da Faturação só criam dados mestres (`Cliente`, `Produto`, `CategoriaProduto`); os documentos passam pelos serviços.
- Valores monetários calculados em cêntimos inteiros (`Dinheiro`), sem `float` nos serviços e no contrato fiscal.
- Testes: bateria `Faturacao` sem falhas; CI do PR (qualidade, composer-audit, testes-mysql com concorrência real) verde.

## Corrigido nesta fase

| Id | Prioridade | Problema | Correção |
|----|-----------|----------|----------|
| C-01 | P1 | `FaturaService::atualizarRascunho` e `eliminarRascunho` sem guarda de tenant | `GuardaEmissao::garantirTenant` |
| C-02 | P1 | Gateway sem notas de crédito/débito nem anulação | `criarNotaCredito`, `criarNotaDebito`, `anularFatura`, `emitirNota` |
| C-03 | P2 | Orçamento aceitava validade no passado ao criar | regra única `garantirValidadeNaoPassada` |
| C-04 | P2 | `converter`/`duplicar` dependiam do tenant ambiente (falhavam em jobs) | cliente procurado pela empresa do orçamento |
| C-05 | P2 | Regra de tenant duplicada no `OrcamentoService` | usa `TenantManager::garantirEmpresa` |
| C-06 | P2 | `getValorTotalDocumento(): float` no contrato fiscal, sem chamadores | removido |
| C-07 | P2 | Sem forma de os verticais obterem a taxa de IVA da empresa | `taxaIvaPadrao()` no gateway |
| C-08 | P3 | Docblocks com `float` e docblock órfão em `NotaCreditoDebitoService` | corrigidos |

## Em aberto (não resolvidos nesta fase)

| Id | Prioridade | Fase | Descrição |
|----|-----------|------|-----------|
| A-01 | P1 | 6 | Estúdio (3 pontos de `FaturacaoEstudioService` e `ProjetoMusical::pacoteComIvaCentimos`) usa a taxa de IVA global em vez de `taxaIvaPadrao()` |
| A-02 | P1 | 6 | Atelier (`PedidoController`) usa a taxa de IVA global em vez de `taxaIvaPadrao()` |
| A-03 | P1 | 6/7 | Nota de crédito não repõe stock (a FT baixa stock; as linhas da nota não têm `produto_id`). Requer decisão de negócio sobre devoluções |
| A-04 | P1 | 6 | Estúdio emite o sinal como FT; deveria usar FA + abatimento, que o gateway já suporta |
| A-05 | P2 | 6/7 | Atelier: o sinal é um recibo sem `fatura_id`; após o pagamento final o saldo da fatura (`resumoDaFatura`) fica igual ao sinal, enquanto a conta corrente do cliente fecha a zero |
| A-06 | P2 | 6 | `SolicitacaoOrcamentoController::converter` (Estúdio) cria `Cliente` com `forceFill`, fora de um serviço central |
| A-07 | P2 | 4/5 | Emissão de RC bloqueada com AGT ativa; criar rascunho exige já uma série AGT autorizada |
| A-08 | P3 | 6 | Nota de crédito parcial criada pelo formulário perde o `preco_unitario_base` original |
| A-09 | P3 | 8 | Formulários de editar fatura/nota passam quantidades e preços a `float` para o ecrã (não afeta totais) |
| A-10 | P3 | — | Numeração dos orçamentos aleatória (`ORC-AAAAMMDD-XXXXXXXX`), não sequencial; não é documento fiscal |
| A-11 | P3 | 5 | `ContaCorrenteClienteService::movimentosAte` tem um parâmetro não usado |

## Limites desta auditoria

- Leitura estática dos serviços principais e testes automáticos; sem execução contra a AGT.
- A varredura de `withoutGlobalScopes()` é por proximidade (4 linhas); ocorrências com a cadeia mais longa exigem revisão manual.
- Conformidade fiscal e certificação AGT não estão demonstradas.
