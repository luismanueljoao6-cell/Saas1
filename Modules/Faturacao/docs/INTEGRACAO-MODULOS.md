# Integração dos módulos com o núcleo Faturação

## Contrato público

Os módulos de negócio devem depender de `Modules\\Faturacao\\Contracts\\FaturacaoGateway`, resolvido pelo contentor Laravel no `FaturacaoServiceProvider`.

O gateway é a fronteira pública para:
- criar rascunhos de fatura;
- emitir faturas;
- emitir recibos quando o tipo documental estiver fiscalmente suportado;
- consultar saldo e resumo de pagamentos.

A implementação delega nos serviços de domínio do módulo Faturação. Os módulos consumidores não devem:
- reservar números ou criar séries;
- assinar documentos ou construir hashes;
- calcular IVA ou totais fiscais;
- alterar documentos depois da emissão;
- submeter documentos à AGT directamente.

## Linhas fiscais

Cada linha pode declarar:
- `operation_type`: classificação AGT da operação (por exemplo, `SG` serviço geral ou `TB` transmissão de bens);
- `preco_unitario_base`: preço unitário antes de descontos;
- `preco_unitario`: preço unitário líquido, depois de descontos.

Se `preco_unitario_base` não for fornecido, o preço base é igual ao preço final. A diferença, multiplicada pela quantidade, é enviada em `settlementAmount`. O valor contabilístico da linha continua a ser calculado a partir do preço final e da taxa de IVA.

As notas de crédito/débito preservam a classificação e o preço base das linhas de origem.

## Integrações existentes

- **Atelier**: serviço principal classificado como `SG`; materiais classificados como `TB`. Criação de rascunhos e recibos através do gateway.
- **Estúdio de Música**: serviços classificados como `SG`; descontos de cupão enviados como preço base/preço líquido e `settlementAmount`.
- **Novos módulos**: devem usar exclusivamente o gateway e declarar `operation_type` quando a operação não for serviço geral.

## Limitações fiscais explícitas

- **Adiantamentos**: o tipo AGT `FA` ainda precisa de implementação completa e validação da relação com a fatura final. Os fluxos de sinal do Atelier/Estúdio não podem ser considerados prontos para submissão AGT enquanto isso não estiver concluído.
- **Recibos**: o model actual emite `RC`, cujo payload exige linhas. O modelo ainda não guarda essa estrutura. `paymentReceipt` é reservado pela especificação AGT para `AR` e `RG`; por isso, a emissão com AGT activa está bloqueada até existir mapeamento documental correcto.
- **Homologação**: testes locais e CI não substituem a certificação/homologação formal do software pela AGT.

## Regra de evolução

Alterações à API pública devem ser compatíveis com os módulos consumidores e acompanhadas de testes de integração que cubram Faturação, Atelier, Estúdio e o isolamento entre empresas.
