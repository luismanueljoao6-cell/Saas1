## Objetivo
Consolidar a Faturação como núcleo fiscal único do SaaS1 e fechar lacunas de cálculo, documentos, séries, assinatura, comunicação AGT e SAF-T AO, preservando o comportamento já existente.

## Âmbito e correções especificadas
1. **Motor monetário/fiscal (FIS-precision, FIS-041/042):**
   - Rever precisão decimal, escala e regra de arredondamento por campo e por documento segundo especificação AGT aplicável.
   - Separar truncagem, arredondamento convencional e arredondamento por excesso quando a norma assim determinar.
   - Centralizar cálculo de base tributável, IVA, descontos, retenções, isenções e totais; módulos consumidores não recalculam fiscalidade.
   - Adicionar testes de valores fracionários de cêntimo, múltiplas linhas, descontos e impostos combinados.
2. **Documentos e adiantamentos:**
   - Validar FT, FR, FA, RC/RG, NC, ND, VD e demais tipos suportados contra a versão oficial vigente.
   - Confirmar campos e nomes JSON case-sensitive, incluindo `OriginatingON` vs `originatingON`, e estrutura de `paymentReceipt` (debitAmount/creditAmount).
   - Confirmar snapshots fiscais imutáveis, referências a documentos originais, estados e regras de anulação/correção.
3. **Séries e numeração (FIS-041/042/043):**
   - Validar metadados de série ao atualizar/extender autorização: tipo, exercício, estabelecimento, intervalo e estado.
   - Garantir continuidade do intervalo autorizado e bloqueio de números fora do intervalo.
   - Propagar estabelecimento correto em todas as emissões, incluindo notas e recibos, e testar concorrência.
4. **Assinatura e integração AGT:**
   - Comparar serialização JWS, cabeçalhos, bytes assinados, algoritmo e payload com especificação oficial vigente e ambiente de homologação.
   - Não alterar `typ`, nomes de campos ou estrutura com base apenas em SDK comunitário; usar contrato oficial e evidência de homologação.
   - Reforçar idempotência, timeout, reenvio, consulta de estado, rejeição e recuperação sem gerar novo número/documento indevidamente.
5. **SAF-T AO (FIS-058, QA-004/005):**
   - Validar XML com XSD oficial aplicável e regras de negócio; distinguir ficheiro gerado, validado por XSD e aceite pela AGT.
   - Rever recibos, NC/ND, compras, fornecedores, pagamentos, retenções, moeda, datas e tabelas fiscais conforme XSD vigente.
   - Corrigir publicação XML + metadados para evitar estado parcial; preservar versão anterior até publicação completa.
   - Definir retries seguros e recuperação de jobs sem duplicar exportações.
6. **Evidência de conformidade:**
   - Registar versão/data/hash dos esquemas oficiais, ambiente e resultado de homologação; não afirmar certificação sem prova documental.

## Critérios de aceitação
- Cálculos reproduzíveis e cobertos por testes de precisão.
- Payloads e JWS validados contra documentação oficial atual e homologação, não apenas comparação comunitária.
- Numeração e séries não permitem duplicação, salto silencioso ou emissão fora da autorização.
- SAF-T só é rotulado como validado quando XSD oficial foi aplicado; aceite AGT é estado separado.
- Falhas e reenvios não duplicam documentos nem perdem histórico.

## Testes obrigatórios
- Testes unitários de cálculo fiscal e arredondamento.
- Fixtures de payload/JWS com bytes esperados e casos de Unicode, null, datas e decimais.
- Testes MySQL de concorrência de séries, emissão, NC e submissão.
- Validação XSD oficial versionada, testes SAF-T de todos os documentos e reconciliação com totais.
- Testes de falha parcial na publicação, timeout e recuperação de filas.

## Dependências
A Fase 1 deve estabilizar a reconciliação de adiantamentos, recibos e abatimentos antes de concluir os testes de ciclo fiscal completo.

## Fora do âmbito
- Construção de funcionalidades de gestão não fiscais.
- Cópia de funcionalidades de terceiros; Vendus é apenas referência comparativa.