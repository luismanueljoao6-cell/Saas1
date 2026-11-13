# Arquitetura do módulo Armazém

Estado: proposta técnica inicial  
Branch: `feat/armazem-modulo-base`  
Dependência de integração: PR #38 (`feat/vendus-gestao-integrada`)

## 1. Objetivo e limites de responsabilidade

O Armazém é um módulo de gestão operacional de existências, independente da Faturação.

- **Core** mantém empresas, utilizadores, autenticação, tenancy e permissões globais.
- **Faturação** mantém o catálogo comercial, fornecedores, compras e documentos fiscais/comerciais.
- **Armazém** mantém armazéns físicos, saldos por localização, movimentos, transferências, inventários e reservas.
- **Atelier** e outros módulos consumidores pedem operações ao Armazém através de contratos públicos; não escrevem diretamente nas tabelas de stock.

A compra continua a pertencer à Faturação. A confirmação da receção chama o contrato do Armazém, que regista a entrada física. O registo de pagamento de fornecedor não é uma operação de stock.

## 2. Estrutura proposta

```text
Modules/Armazem/
  app/
    Contracts/
    DTOs/
    Enums/
    Events/
    Exceptions/
    Http/Controllers/
    Http/Requests/
    Models/
    Policies/
    Providers/
    Services/
  database/migrations/
  resources/views/
  routes/
  tests/Feature/
  module.json
  README.md
```

O módulo terá provider próprio, rotas, permissões e testes. O registo no Composer/autoload e no estado dos módulos será feito apenas na fase de implementação, com atualização coerente dos ficheiros de configuração.

## 3. Modelo de dados alvo

### armazens
- `id`, `empresa_id`, `codigo`, `nome`, `descricao`, `morada`
- `principal`, `ativo`, timestamps
- unicidade de `(empresa_id, codigo)`
- no máximo um armazém principal ativo por empresa, garantido pela aplicação e, quando suportado, por índice adequado.

### stock_saldos
- manter `empresa_id` e `produto_id`
- adicionar `armazem_id`
- quantidade decimal com três casas
- unicidade final `(empresa_id, armazem_id, produto_id)`

### stock_movimentos
- adicionar `armazem_id`
- manter quantidade assinada, saldo anterior/posterior, motivo, utilizador e chave de idempotência
- a chave de idempotência mantém unicidade por empresa: `(empresa_id, chave_idempotencia)`. Não a tornar única apenas por armazém, pois isso permitiria repetir a mesma operação lógica em locais diferentes.

### reservas de stock
- tabela própria para reservas, sem criar movimento físico
- empresa, armazém, produto, origem/tipo e identificador externo, quantidade, estado, expiração e utilizador
- a quantidade disponível é saldo físico menos reservas ativas
- confirmação/consumo e cancelamento têm de ser transacionais e idempotentes.

### inventários
- cabeçalho de sessão de inventário e linhas com quantidade esperada no início, quantidade contada, diferença e motivo
- a conclusão publica apenas a diferença como movimento de ajuste; o histórico não é apagado nem reescrito.

## 4. Migração sem perda de dados

A migração é aditiva e faseada. Não editar nem reescrever a migração histórica `2026_11_13_000028_create_stock_inventory_tables.php`, que pode já ter sido aplicada em ambientes reais.

1. Criar a tabela `armazens`.
2. Criar um armazém principal por empresa que já tenha produtos/stock controlado ou saldos.
3. Adicionar `armazem_id` inicialmente nullable às tabelas de saldos e movimentos.
4. Preencher as linhas existentes com o armazém principal da respetiva empresa.
5. Validar que não existem linhas sem armazém nem duplicados por empresa/armazém/produto.
6. Tornar `armazem_id` obrigatório e criar a nova unicidade.
7. Preservar IDs, quantidades, movimentos, chaves de idempotência e timestamps originais.
8. Só remover a antiga unicidade de saldo depois de a nova estar validada.

A migração deve ser testada tanto numa base vazia como numa base que já tenha dados de stock. O rollback não pode apagar armazéns nem movimentos reais; a estratégia de reversão deve ser conservadora e documentada.

## 5. Contrato entre módulos

A Faturação não deve depender de modelos internos do Armazém. O contrato público deve expor operações de aplicação, por exemplo:

- registar entrada por receção de compra;
- consultar disponibilidade;
- reservar quantidade;
- libertar reserva;
- consumir reserva;
- registar saída operacional.

Os pedidos incluem sempre empresa, produto, quantidade, motivo, utilizador quando aplicável e uma chave de idempotência estável. A implementação valida o tenant ativo, a empresa do produto e o acesso ao módulo.

A receção de uma compra chama o contrato dentro da mesma transação de negócio. Se a entrada falhar, a compra não pode ficar marcada como recebida.

## 6. Regras de consistência e concorrência

- Quantidades são calculadas em milésimos inteiros, sem aritmética de ponto flutuante.
- Bloquear linhas de saldo com `lockForUpdate` antes de validar e atualizar.
- Em transferências, bloquear os dois saldos sempre pela mesma ordem determinística (empresa, armazém, produto) para reduzir deadlocks.
- Uma transferência cria saída e entrada vinculadas, dentro da mesma transação.
- Não permitir saldo físico negativo nem disponibilidade negativa.
- Repetir uma chave com os mesmos dados devolve o movimento original; reutilizá-la com dados diferentes gera conflito.
- Movimentos lançados são imutáveis; correções são novos movimentos compensatórios.
- Toda a consulta e escrita é limitada à empresa, mesmo quando um serviço executa sem contexto tenant explícito.

## 7. Permissões e ativação

Permissões iniciais propostas:
- `armazem.ver`
- `armazem.movimentar`
- `armazem.transferir`
- `armazem.inventariar`
- `armazem.reservar`
- `armazem.configurar`

A autorização deve verificar simultaneamente a permissão do utilizador e a ativação do módulo/serviço para a empresa. A ausência de contexto ou de ativação é recusada por omissão.

## 8. Plano de implementação

1. **Fundação:** provider, module.json, permissões, contrato e testes de registo do módulo.
2. **Migração compatível:** armazém principal e backfill dos saldos/movimentos atuais.
3. **Extração:** modelos e serviço de stock passam para Armazém, mantendo adaptador temporário para chamadas existentes.
4. **Integração de compras:** receção de compras chama o contrato; testar rollback e idempotência.
5. **Interface:** painel, listagem de armazéns, movimentos e filtros por localização.
6. **Operações avançadas:** transferências, inventários e reservas.
7. **Remoção de legado:** retirar controlador/rotas/modelos antigos de Faturação apenas depois de todos os consumidores migrarem.

## 9. Critérios obrigatórios de aceitação

- Instalação limpa e atualização de base existente sem perda de dados.
- Testes SQLite e MySQL.
- Isolamento multiempresa em todas as operações e consultas.
- Concorrência de entradas/saídas/transferências sem saldo incorreto.
- Idempotência em receções, transferências e consumo de reservas.
- Falha a meio de uma operação não deixa movimentos parciais nem compra falsamente recebida.
- Testes de permissões e módulo desativado.
- Verificação visual em ecrã pequeno e desktop antes de integrar na main.

## 10. Dependência de integração

Este trabalho é inicialmente uma branch empilhada sobre o PR #38, porque o PR introduz o catálogo, as compras e o serviço de stock que serão extraídos. O PR #38 deve ser integrado primeiro; depois a base deste PR deve ser atualizada para `main` e os testes executados novamente. Nenhuma destas etapas autoriza alterações diretas à `main`.
