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


## Fase de transferências — implementação inicial

Foi acrescentada a primeira implementação do ciclo de vida de transferências, incluindo uma interface web operacional inicial:

- Tabelas `armazem_transferencias` e `armazem_transferencia_linhas`, com origem/destino, empresa, responsáveis, estado, datas e quantidades.
- Estados: `rascunho`, `em_transito`, `recebida` e `cancelada`.
- Criação de rascunho com validação de empresa, armazéns ativos e distintos, produtos controlados por stock e quantidades positivas.
- Expedição debita o armazém de origem através do serviço de stock e usa chave idempotente por transferência/linha/etapa.
- Receção credita o destino com chave idempotente independente e regista a quantidade recebida.
- Repetição de expedição/receção não duplica movimentos; apenas rascunhos podem ser cancelados.
- As operações são transacionais e bloqueiam a empresa, transferência e linhas para serializar alterações concorrentes.
- Testes acrescentados para fluxo completo, stock insuficiente com rollback, repetição idempotente, cancelamento, tenant e validação de armazéns/quantidades.

### Receção parcial e divergências — fase implementada em desenvolvimento

A transferência passa a admitir várias sessões de receção, com histórico imutável por evento:

- Cada evento tem chave de idempotência única por empresa e hash do payload; repetir a mesma chave com os mesmos dados não duplica movimentos, e reutilizá-la com outros dados é recusado.
- Cada linha acumula quantidades recebidas, em falta e danificadas, sem ultrapassar a quantidade expedida.
- Apenas a quantidade efetivamente recebida gera entrada no stock do destino.
- Quantidades em falta e danificadas ficam registadas no histórico e nunca são creditadas no destino.
- Uma receção pode ficar parcialmente recebida e continuar em trânsito.
- A finalização exige que toda a quantidade expedida esteja classificada como recebida, em falta ou danificada.
- O estado final distingue receção sem divergências de receção com divergências.
- O botão de receção integral existente passa a receber apenas o remanescente ainda não classificado.
- Armazéns associados a transferências parcialmente recebidas continuam protegidos contra inativação.

### Limitações ainda abertas

- Não existe ainda um fluxo formal de investigação e resolução posterior de faltas/danos, nem compensação ou devolução após a expedição.
- A interface web inicial já permite criar transferências com várias linhas, consultar histórico, expedir, receber e cancelar rascunhos; ainda precisa de revisão visual e de usabilidade em telemóvel.
- A inativação de armazéns de origem/destino está bloqueada enquanto existir transferência em trânsito associada.
- Ainda falta política para devolução/compensação depois da expedição.
- O saldo legado da Faturação continua separado e não foi alterado.
- Esta implementação só deve ser ativada após CI verde e revisão visual/funcional.


## Inventário físico — implementação inicial

O módulo passa a disponibilizar sessões de inventário físico por armazém:

- A abertura exige seleção explícita dos produtos a contar e guarda um snapshot do saldo de cada produto naquele armazém.
- Cada linha regista quantidade contada e diferença face ao snapshot, com precisão até três casas decimais.
- Enquanto uma sessão está em contagem, as operações normais de entrada, saída e ajuste para os produtos incluídos ficam bloqueadas naquele armazém.
- A submissão exige contagem de todas as linhas e regista quem abriu, contou e submeteu a sessão.
- Se não houver diferenças, o inventário pode ser concluído sem gerar movimentos de ajuste.
- Se houver diferenças, a sessão passa a `aguarda_aprovacao`; os saldos permanecem inalterados e os movimentos normais continuam bloqueados.
- A aprovação exige um utilizador diferente de quem abriu, submeteu ou registou qualquer contagem da sessão. Só depois da aprovação cada diferença é aplicada por `ArmazemStockService::ajustar()`, com chave idempotente determinística por sessão/produto.
- A aprovação decorre numa única transação. Se um ajuste falhar, a sessão e todos os movimentos são revertidos; repetir a aprovação concluída não duplica movimentos.
- A rejeição exige motivo e responsável, fica auditada e liberta os produtos sem alterar os saldos.
- As permissões de aprovar/rejeitar são separadas das permissões de contar/submeter. O provisionamento do módulo acrescenta permissões sem remover as de outros módulos.
- A interface permite consultar diferenças, responsáveis, estado, motivo de rejeição e ações de submissão/aprovação/rejeição.

### Limites desta fase

- A seleção de produtos é explícita; não é criado automaticamente um inventário de todo o catálogo.
- O inventário só cobre o novo stock multi-armazém. O stock legado da Faturação continua separado.
- A implementação ainda precisa de CI verde, teste visual em browser/telemóvel e validação de concorrência real no MySQL antes de produção.


## Aprovação segregada de ajustes — entrega em revisão

- Inventários com divergências aguardam aprovação independente; não alteram o saldo durante a submissão.
- O aprovador não pode ser quem abriu, submeteu ou contou qualquer linha do inventário.
- A rejeição regista responsável, data e motivo; a sessão rejeitada deixa de bloquear movimentos.
- A aprovação aplica apenas diferenças não nulas, com movimentos imutáveis e idempotentes.
- O comando `php artisan core:provisionar-servicos` repara permissões de empresas existentes; o provisionador foi alterado para acrescentar as permissões do Armazém sem substituir as permissões de outros módulos.
- Esta entrega ainda depende da CI do PR e de revisão visual; não está ativada em produção.


## Decisão de integração do ledger — aplicada nesta fase

A separação dos dois ledgers deixou de ser aceite como estado final. A partir desta integração:

- **Fonte de verdade:** `armazem_stock_saldos` e `armazem_stock_movimentos`.
- **Compatibilidade:** `stock_saldos` passa a ser apenas uma projeção agregada (soma de todos os armazéns); não deve receber escritas de negócio independentes.
- **Vendas/faturas e compras recebidas:** os fluxos legados que ainda não transportam `armazem_id` são encaminhados pelo `StockService` para o armazém principal ativo da empresa. A saída/entrada continua a usar a chave idempotente do documento/linha.
- **Inventários e transferências:** continuam a movimentar o armazém explicitamente selecionado. Cada movimento atualiza a projeção agregada na mesma transação.
- **Ajustes chamados pela API antiga:** representam a contagem física do armazém principal, não a contagem do total consolidado da empresa.
- **Precisão:** agregações são feitas em milésimos inteiros; não se convertem quantidades para `float`.

### Reconciliação antes do cutover

A migração `2026_11_16_000001_unificar_saldo_legado_com_armazens` faz uma verificação prévia de todos os pares empresa/produto:

1. Se ainda não existir qualquer saldo por armazém, copia o saldo legado para o armazém principal.
2. Se já existirem saldos por armazém, exige que a soma seja exatamente igual ao saldo legado.
3. Se encontrar qualquer divergência, aborta antes de escrever e identifica os pares que precisam de reconciliação manual. Não soma os dois valores nem escolhe silenciosamente um deles.
4. Depois da reconciliação, atualiza a tabela antiga como projeção agregada.

A reversão automática desta migração é intencionalmente desativada porque os saldos podem já ter recebido movimentos reais. Antes de produção, executar a migração numa cópia da base de dados e confirmar que não há divergências. A falha da migração é um bloqueio de deploy, não uma razão para ignorar a diferença.

### Evolução posterior

A escolha do armazém principal é o comportamento de compatibilidade para documentos antigos. Uma fase posterior pode adicionar `armazem_id` explícito a faturas e compras, com seleção e snapshot no documento, sem alterar a regra desta fase nem permitir que o cliente envie um armazém de outra empresa. Até essa fase, o armazém principal é o destino/origem determinístico.

### Verificações de aceitação desta integração

- Compra recebida e fatura emitida movimentam o ledger por armazém através do adaptador central.
- Operações de inventário e transferência atualizam o mesmo saldo consolidado.
- A soma dos saldos por armazém corresponde à projeção legada após cada movimento.
- Repetição idempotente não cria movimento duplicado.
- Divergência entre os dois modelos bloqueia a migração antes de qualquer alteração de saldo.
