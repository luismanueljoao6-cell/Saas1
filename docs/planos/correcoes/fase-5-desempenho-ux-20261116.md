## Objetivo
Melhorar desempenho e usabilidade com medições e testes, sem sacrificar precisão fiscal, segurança, acessibilidade ou funcionalidades existentes.

## Âmbito e correções especificadas
1. **Consultas e desempenho (PERF-001 a PERF-016):**
   - Eliminar N+1 na exportação SAF-T e nos relatórios/conta corrente.
   - Reduzir consumo de memória na geração XML; avaliar streaming XML em vez de árvore DOM integral quando compatível com validação XSD.
   - Otimizar saldo inicial da conta corrente sem carregar todo o histórico para PHP.
   - Rever locks de stock e consultas agregadas; reduzir contenção mantendo invariantes de concorrência.
   - Medir índices fiscais e operacionais com EXPLAIN/EXPLAIN ANALYZE em MySQL e volumes representativos.
   - Avaliar Redis para cache/queue/locks/sessions e filas separadas com base em métricas, não por alteração cega de defaults.
2. **Interface e acessibilidade (UI-001 a UI-025, SUB-UI, CORE-UI, AT-UI, EST-UI):**
   - Uniformizar layout, componentes, moeda AOA, datas e timezone.
   - Melhorar navegação por teclado, foco, labels, aria-expanded, aria-current e feedback de ações.
   - Garantir tabelas e formulários responsivos em telemóveis.
   - Distinguir estado documental/fiscal/AGT/financeiro/logístico em compras, faturas, notas, recibos e projetos.
   - Melhorar resumo de adiantamentos, pagamentos, abatimentos e conta corrente.
   - Rever estados vazios e placeholders para nunca apresentar dados demonstrativos como reais.
3. **Design system e consistência:**
   - Consolidar componentes reutilizáveis sem introduzir abstrações excessivas.
   - Corrigir inconsistências de layouts e secções Blade após confirmar aliases e rotas ativas.
   - Remover dependências CDN apenas quando houver alternativa local testada e política de atualização.
4. **Regressão visual e funcional:**
   - Comparar vistas em telemóvel/tablet/desktop e validar fluxos reais.

## Critérios de aceitação
- Melhorias de desempenho demonstradas por métricas antes/depois.
- Nenhuma regressão nos cálculos, isolamento ou fluxos fiscais.
- Fluxos essenciais utilizáveis por teclado e leitores de ecrã.
- Layouts responsivos sem perda de ações ou dados.
- Estados apresentados correspondem ao estado persistido no servidor.

## Testes obrigatórios
- Testes de consultas e benchmarks com volumes representativos.
- EXPLAIN ANALYZE em MySQL para consultas prioritárias.
- Testes de acessibilidade automatizados e revisão manual por teclado.
- Testes visuais em tamanhos móveis e desktop.
- Testes de regressão dos fluxos de faturação, compras, stock, Ateliê, Estúdio e subscrições.

## Dependências
Executar após estabilização das fases 1 a 4. Alterações de performance devem manter os contratos fiscais e de segurança.