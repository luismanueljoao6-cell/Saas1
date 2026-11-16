## Objetivo
Reduzir acoplamento entre módulos e assegurar que a base de dados representa as mesmas invariantes de negócio impostas pelos serviços, sem quebrar contratos ou dados existentes.

## Âmbito e correções especificadas
1. **Dependências modulares (ARQ-001 a ARQ-010):**
   - Resolver dependência circular Armazém ↔ Faturação.
   - Definir contratos públicos de stock/produtos sem expor modelos Eloquent internos.
   - Fazer Armazém consumir abstração de catálogo/produto em vez de importar diretamente o modelo de Faturação.
   - Reduzir dependência direta de modelos Eloquent e classes monetárias internas nos módulos Ateliê/Estúdio, mantendo FaturacaoGateway como fronteira.
   - Rever ordem e requisitos de carregamento dos módulos e provisionadores.
2. **Integridade referencial multiempresa (DB-001/006/010/016):**
   - Introduzir FKs compostas ou constraints equivalentes para impedir referências cruzadas entre empresas.
   - Aplicar coerência a compras, pagamentos, armazéns, produtos, movimentos, documentos e abatimentos.
   - Rever unicidade estrutural do armazém principal por empresa.
3. **Histórico financeiro (DB-002/011):**
   - Remover cascades que apaguem pagamentos ou evidências financeiras; definir política explícita de retenção, anulação e arquivo.
   - Garantir que documentos emitidos e respetivos snapshots não são apagáveis por operações de rotina.
4. **Migrations de stock (DB-007/008/009/014/015/017):**
   - Reescrever migrações em lotes/streaming, sem carregar toda a tabela em memória nem executar N+1.
   - Definir transação/estratégia de migração segura por motor de BD, checkpoints, validação prévia e pós-migração.
   - Documentar rollback ou estratégia de recuperação quando reversão destrutiva não for segura.
   - Testar migração com volumes representativos e dados inconsistentes.
5. **Provisionamento e contratos:**
   - Tornar provisionamento modular repetível, transacional quando possível e recuperável após falha parcial.
   - Garantir paridade de constraints e comportamento SQLite/MySQL; não enfraquecer constraints apenas para satisfazer SQLite.

## Critérios de aceitação
- Grafo de dependências modular sem ciclos proibidos.
- Nenhum registo financeiro ou stock pode referenciar entidade de outra empresa.
- Migrations processam volumes grandes com memória e tempo controlados.
- Migrações têm plano de backup, validação e recuperação documentado.
- Contratos públicos não expõem detalhes internos desnecessários.

## Testes obrigatórios
- Testes de arquitetura/dependências e contratos entre módulos.
- Testes de FK/constraints com empresas diferentes.
- Testes de provisionamento repetido e falha parcial.
- Testes de migração em MySQL e SQLite, incluindo dados históricos e volume elevado.
- Testes de regressão para APIs internas e módulos consumidores.

## Dependências
Executar após as correções de integridade crítica da Fase 1; coordenar com Fase 2 para não alterar contratos fiscais durante validação AGT.