# Plano de evolução para stock multi-armazém

## Estado atual

O módulo Armazém gere localizações físicas, mas o stock operacional continua no módulo Faturação:

- `stock_saldos`: saldo por empresa e produto.
- `stock_movimentos`: histórico por empresa e produto.
- `StockService`: entradas, saídas e ajustes com transação, bloqueio de saldo e chave de idempotência.
- `CompraService::receber()`: recebe cada linha e chama `StockService::entrada()`.

Não adicionar `armazem_id` às tabelas existentes enquanto houver leitores/escritores que assumem uma única linha por `(empresa_id, produto_id)`. Isso poderia duplicar saldos, tornar relatórios ambíguos e quebrar saídas de venda.

## Contrato funcional pretendido

Toda operação de stock multi-armazém deve identificar explicitamente:

- empresa;
- armazém;
- produto;
- operação (`entrada`, `saida`, `ajuste`, futuramente `transferencia`);
- quantidade decimal até três casas;
- motivo e utilizador;
- chave de idempotência quando a operação vier de um fluxo repetível (compra, venda, API ou job).

O serviço deve validar que empresa, armazém e produto pertencem ao mesmo tenant, que o armazém está ativo e que o produto controla stock. O cliente nunca escolhe `empresa_id` nem pode ultrapassar o tenant autenticado.

## Modelo de dados proposto

A evolução deve criar tabelas novas, sem reescrever o histórico:

### `armazem_stock_saldos`

- `id`
- `empresa_id`
- `armazem_id`
- `produto_id`
- `quantidade` decimal(14,3), não negativa
- timestamps
- unique `(empresa_id, armazem_id, produto_id)`
- índices para consultas por empresa/armazém e produto

### `armazem_stock_movimentos`

- `id`
- `empresa_id`, `armazem_id`, `produto_id`
- `utilizador_id` nullable
- `tipo` (`entrada`, `saida`, `ajuste`, futuramente movimentos de transferência)
- `quantidade` decimal(14,3), com sinal para representar a variação
- `saldo_anterior` e `saldo_posterior` decimal(14,3)
- `motivo`
- `chave_idempotencia` nullable, única por empresa
- timestamps

A unicidade da chave deve ser garantida na base de dados, não apenas por uma consulta prévia. As FKs de armazém/produto devem impedir movimentos órfãos; o histórico não deve ser apagado em cascata quando um produto ou armazém já tiver movimentos.

## Migração e compatibilidade

1. Criar tabelas novas e respetivos índices/FKs.
2. Para cada empresa, obter o armazém principal de forma idempotente.
3. Copiar cada saldo legado para o armazém principal, preservando exatamente a quantidade.
4. Copiar movimentos históricos para o armazém principal, preservando IDs de origem numa coluna de referência ou tabela de correspondência; nunca inventar utilizador/motivo.
5. Executar reconciliação: soma dos saldos por produto nos novos armazéns deve corresponder ao saldo legado antes da troca de leitura.
6. Fazer a migração em modo compatível: escritas continuam no legado e no novo modelo na mesma transação, com chave idempotente estável, até todos os consumidores serem migrados.
7. Comparar relatórios e contagens em produção antes de mudar a leitura principal.
8. Só depois retirar a escrita dupla e, numa versão posterior, descontinuar as tabelas antigas.

A migração deve ser retomável, paginada e segura para empresas grandes. Não executar backfill destrutivo nem recalcular saldos a partir de movimentos sem uma reconciliação explícita.

## Integrações obrigatórias

- **Compras:** a compra deve indicar o armazém de destino; a receção aumenta o saldo desse armazém. A chave idempotente deve manter referência à compra e à linha.
- **Vendas/faturas:** a emissão/baixa deve identificar o armazém de origem e impedir saldo negativo, respeitando as regras atuais de emissão e anulação.
- **Devoluções e notas de crédito:** definir se repõem stock e em qual armazém, com motivo e ligação ao documento original.
- **Relatórios:** mostrar saldo por armazém e consolidado, sem somar duas vezes dados legados e novos.
- **API/jobs:** validar tenant, armazém e chave idempotente; nunca confiar no `empresa_id` enviado pelo cliente.

## Transferências

Uma transferência não é uma entrada/saída isolada. Deve ter cabeçalho com origem, destino, estado, utilizador, motivo e linhas. O fluxo recomendado é:

1. rascunho;
2. confirmação de saída, bloqueando saldos de origem e registando saída;
3. em trânsito;
4. confirmação de receção no destino, registando entrada;
5. divergências tratadas por ajuste autorizado e auditado.

Origem e destino têm de ser diferentes e pertencer à mesma empresa. A operação deve ser idempotente por etapa, com bloqueio consistente para evitar deadlocks e duplicações.

## Inventários e ajustes

- Abrir sessão de inventário por armazém, com responsável e data de referência.
- Guardar contagem física por produto e snapshot do saldo esperado.
- Não alterar saldo durante a contagem sem política explícita de congelamento ou reconciliação.
- Fechar inventário com diferenças calculadas e motivo obrigatório.
- Gerar movimentos de ajuste auditáveis; nunca editar diretamente o saldo.

## Critérios de aceitação antes de ativar

- Testes MySQL de concorrência e idempotência.
- Testes de isolamento entre duas empresas.
- Testes de compra recebida, venda, devolução, transferência e inventário.
- Reconciliação automática legado vs. novo modelo.
- Teste de migração numa cópia anonimizada de base existente.
- UI responsiva com seleção clara de armazém e indicação do contexto atual.
- Logs/auditoria para operações administrativas e falhas de reconciliação.

## Decisão nesta fase

O PR #39 não altera ainda o stock legado. A primeira entrega multi-armazém deve começar por tabelas novas, serviço de domínio e testes, e só depois integrar compras e vendas por adaptadores. Esta separação é intencional para preservar os saldos e documentos existentes.
