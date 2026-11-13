# Auditoria da interface — 13/11/2026

## Âmbito e método

Auditoria estática da branch `main`, cruzando rotas, controllers, views Blade, layout global, menu dinâmico e documentação dos módulos. Não foi possível executar Laravel, Vite, browser ou testes de ponta a ponta através da ligação GitHub; por isso, este documento separa evidência observada de validações ainda necessárias. Não representa certificação AGT nem validação visual em browser.

## Estado do repositório observado

- PR #35 (`fix/registo-sessao-segura-2026-11`): aberta, draft, não integrada na consulta.
- PR #36 (`feat/faturacao-documentos-vendus`): aberta, draft, não integrada na consulta; inclui melhorias de proformas e não deve ser considerada publicada em main.
- A documentação do projeto identifica Core, Subscrições e Faturação como testados; Atelier e Estúdio como em desenvolvimento.
- O README declara explicitamente que o módulo de faturação não está certificado pela AGT. A interface não deve sugerir o contrário.

## Mapa verificado

| Área | Backend/rotas observadas | Interface observada | Diagnóstico |
|---|---|---|---|
| Core | login, registo, painel, empresa/definições, serviços, utilizadores | layout global, sidebar/header, painel | Estrutura central existe; falta inspeção visual e validação de todos os links/permissões em browser. |
| Faturação | painel, orçamentos, clientes/conta corrente, produtos/categorias, stock, faturas, notas, recibos, SAF-T | views para painel, listas/detalhes/formulários em pastas próprias | Cobertura funcional extensa, mas várias listas usam largura limitada e padrões visuais antigos/inconsistentes. |
| Orçamentos/proformas | index/criar/editar/mostrar, guardar/atualizar, estado, converter | lista confirmada; PR #36 acrescenta pesquisa/filtros/expiração/duplicação | Parte da evolução está apenas na PR #36; não aparece na main até integração. |
| Subscrições | planos, iniciar subscrição, renovação, pagamento pendente; webhook/API no módulo | lista de planos e página de pagamento pendente documentadas | README declara confirmação manual sem UI, lembretes de renovação por fazer e gateways alternativos fora do âmbito. |
| Atelier | rotas administrativas de pedidos, provas, medidas, CRM, equipa, perfil, portfólio e campanhas; landing e portal público | views/controllers existem segundo estrutura do módulo; cobertura visual integral por página ainda não verificada | Módulo em desenvolvimento; requer inventário completo de rotas, menus, permissões e views. |
| Estúdio de Música | rotas de landing pública, portal por token, salas, projetos/faixas/sessões, equipa e termos | views/controllers do módulo; portal separado da área autenticada | Módulo em desenvolvimento; validar correspondência integral entre cada ação e UI. |
| Mobile/tablet | layout global inclui viewport e sidebar responsiva com overlay | componentes usam Tailwind responsivo em vários ecrãs | Base responsiva existe, mas não foi possível fazer testes reais em larguras de telemóvel/tablet. |

## Problemas concretos encontrados

1. **Listas de faturação demasiado estreitas:** as páginas de faturas, clientes, produtos, notas e recibos limitavam o conteúdo a `max-w-2xl/3xl/4xl`, embora o layout global disponibilize `max-w-7xl`. Corrigido nesta branch para aproveitar a largura útil e melhorar a leitura em desktop/tablet.
2. **Formatação monetária inconsistente:** várias views convertem valores com `(float)` antes de `number_format`. Isto é aceitável para apresentação em certos contextos, mas deve ser substituído por um formatter central de apresentação decimal que preserve precisão e escala; nunca reutilizar esta formatação para cálculos ou persistência. A correção exige inspecionar casts/decimais e testes antes de alterar em massa.
3. **Estilos inconsistentes:** algumas listas antigas usam classes sem variantes dark, enquanto o layout global suporta tema escuro. Necessária normalização de estados, formulários, tabelas, avisos e paginação.
4. **Proformas em main incompletas face à evolução:** pesquisa, filtros, estado expirado e duplicação estão descritos na PR #36, não na main consultada.
5. **Subscrições sem interface administrativa completa:** a documentação confirma que a confirmação manual de pagamento ainda não tem UI própria; lembretes de renovação também estão pendentes.
6. **Acesso e navegação:** o Core usa MenuRegistry e filtra itens por rota/serviço. É necessário testar em cada perfil e serviço aderido, pois uma rota existente pode não estar visível por configuração/permissões.
7. **Sem validação visual real:** o acesso GitHub permite ler código, não confirmar que os assets compilam, que o browser não apresenta erros, ou que as páginas ficam corretamente renderizadas.

## Melhorias aplicadas nesta branch

- Faturas: largura máxima alargada para `max-w-7xl`.
- Clientes: largura máxima alargada para `max-w-7xl`.
- Produtos/serviços: largura máxima alargada para `max-w-7xl`.
- Notas de crédito/débito: largura máxima alargada para `max-w-7xl`.
- Recibos: largura máxima alargada para `max-w-7xl`.

Estas alterações não modificam regras fiscais, cálculos, permissões, rotas ou dados.

## Próximas fases de implementação

### P0 — Faturação
- Integrar e rever a PR #36 depois de confirmar CI e compatibilidade com a main atual.
- Criar formatter monetário de apresentação partilhado e testar arredondamento/decimais.
- Uniformizar pesquisa, filtros, paginação, estados vazios, erros e ações nas listas.
- Completar ações de detalhe e navegação contextual entre fatura, recibo, nota e conta corrente.
- Validar acessibilidade, formulários e responsividade.

### P1 — Core e Subscrições
- Validar dashboard, serviços, utilizadores, empresa e menu para cada papel.
- Criar interface de reconciliação/confirmação manual de pagamentos com trilho de auditoria e proteção contra dupla confirmação.
- Implementar lembretes apenas com canal configurado e consentimento aplicável.

### P2 — Atelier e Estúdio
- Construir matrizes rota → controller → view → menu → permissão para todas as funcionalidades.
- Fechar fluxos ponta a ponta de pedido/prova/pagamento/fatura no Atelier.
- Fechar fluxos de projeto/sessão/versão/aprovação/termos no Estúdio.
- Confirmar portais públicos/tokenizados e estados de erro/expiração.

### P3 — Qualidade transversal
- Testes de browser em telemóvel, tablet e desktop.
- Verificação de links e rotas nomeadas.
- Testes de permissões por papel e isolamento multiempresa.
- Confirmar pipeline de build/deploy do ambiente real; merge em main não prova publicação.


## Segunda passagem — navegação e dashboard (13/11/2026)

A leitura integral do registo de navegação e dos layouts revelou problemas adicionais, agora corrigidos nesta branch:

- **Dashboard com dados fictícios:** a view continha números, clientes, faturas e pedidos de demonstração apresentados como se fossem reais. Foram removidos; os indicadores mostram indisponibilidade de dados e as tabelas ficam vazias até receberem dados reais do controller. Isto evita decisões baseadas em informação inventada. A ligação do dashboard a métricas reais continua pendente.
- **Botão Sair:** o header verificava a rota `logout`, mas o Core declara `core.logout`. O formulário foi ligado à rota efetivamente existente.
- **Menu de faturação incompleto:** foram adicionados atalhos para visão geral, orçamentos/proformas e stock (este último apenas para Administrador).
- **Menu Atelier com rotas inexistentes:** foram retirados os destinos para listagens gerais de provas e medidas que não têm rotas index implementadas; foram adicionados os destinos reais para orçamentos recebidos, campanhas, equipa, perfil e portfólio. Os atalhos administrativos foram ocultados a utilizadores sem papel Administrador; os leads do Atelier respeitam os papéis Administrador/Secretária.
- **Menu Estúdio:** substituiu-se o destino inexistente de listagem de versões por rotas reais de pedidos de orçamento, reclamações, equipa e termos. Os pedidos de orçamento respeitam a permissão `estudiomusica.gerir-sessoes`; equipa e termos ficam restritos ao Administrador.
- **Subscrições:** foram retirados dois atalhos para rotas inexistentes (`subscricoes.faturacao` e `subscricoes.pagamentos`); mantém-se o acesso ao plano e à rota de pagamento pendente por ligação contextual.
- **Ícones:** a biblioteca Blade tinha apenas um conjunto mínimo, pelo que vários itens do menu caíam no ícone genérico. Foram acrescentados símbolos para documentos, faturas, utilizadores, empresa, stock, áudio, campanhas, alertas e restantes destinos usados na navegação.
- **Tabelas do Atelier:** os contentores de pedidos, campanhas e equipa passaram a permitir scroll horizontal em ecrãs estreitos.


- **Pesquisa nas listas de faturação:** foram acrescentados filtros GET com paginação preservando a pesquisa para faturas (número/cliente), clientes (nome/NIF/telefone/e-mail), produtos (nome/código/código de barras), notas (número/cliente/motivo), recibos (número/cliente/fatura/meio de pagamento) e movimentos de stock (produto/motivo). As consultas continuam a usar os modelos com âmbito de tenant; não foi removido qualquer isolamento.
- **Tema escuro:** as principais listas de Faturação, Atelier, Estúdio e Subscrições receberam variantes dark para fundos, texto, bordas e divisórias, mantendo o tema claro como padrão.
- **Pesquisa nos módulos operacionais:** foram acrescentadas pesquisas server-side, com validação e paginação preservada, a pedidos, campanhas e leads do Atelier, projetos musicais e pedidos recebidos do Estúdio. A listagem de pedidos mantém a restrição da Costureira aos pedidos atribuídos.

## Estado atualizado das correções

As alterações acima são de apresentação, navegação e honestidade dos dados. Não criam endpoints novos, não alteram permissões no backend, não mudam regras fiscais e não inventam métricas. A cobertura de dados reais no dashboard, as listas gerais de medidas/provas, a confirmação administrativa de pagamentos e a validação visual em browser permanecem tarefas futuras.

## Critérios de conclusão

A auditoria só pode ser considerada concluída quando cada rota de negócio tiver destino de interface ou justificação explícita de ser API/webhook/ação interna; cada ação da UI estiver ligada a uma rota autorizada; os testes e build estiverem verdes; e a validação visual for feita num ambiente executável.

## Fase seguinte — dashboard ligado a dados reais

Na atualização da branch, o painel principal deixou de ser servido diretamente por uma rota de vista e passou a usar `Modules\\Core\\Http\\Controllers\\PainelController`.

- Receita do mês: soma apenas documentos de faturação no estado `emitida`, filtrados pelo mês corrente e pelo tenant ativo.
- Pedidos do Atelier: conta apenas estados não finais; a configuração do módulo define `entregue` e `cancelado` como finais.
- Sessões do Estúdio: conta sessões agendadas, confirmadas ou em curso cujo início previsto ocorre nos próximos sete dias.
- Plano: apresenta utilizadores ativos da empresa e, quando o snapshot da subscrição contém `max_utilizadores` ou `utilizadores`, a utilização face ao limite.
- As consultas dos módulos só são executadas quando a empresa tem o serviço correspondente ativo. Contas sem empresa não agregam dados de tenants.
- As listas recentes do painel passam a receber registos reais de faturas e pedidos, com dados mínimos para apresentação.

### Limites e validações pendentes
- O indicador de faturação representa valor faturado em documentos emitidos, não dinheiro efetivamente recebido; recebimentos devem continuar a ser apresentados separadamente no painel específico de faturação.
- A chave do limite de utilizadores é lida de forma compatível com `max_utilizadores` ou `utilizadores`; outros nomes de limite ainda não são interpretados.
- A execução da CI desta atualização está em curso. É necessário confirmar testes MySQL/SQLite e Pint antes de considerar a alteração validada.
- A validação visual e a criação de testes específicos de regressão para estes indicadores continuam pendentes.
