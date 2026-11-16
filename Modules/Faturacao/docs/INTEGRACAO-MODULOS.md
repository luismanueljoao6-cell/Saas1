# Integração dos módulos com o núcleo Faturação

## Contrato público

Os módulos de negócio devem depender de `Modules\\Faturacao\\Contracts\\FaturacaoGateway`, resolvido pelo contentor Laravel no `FaturacaoServiceProvider`.

O gateway é a fronteira pública para:
- criar rascunhos de fatura (`criarRascunho`) e de fatura de adiantamento FA (`criarRascunhoAdiantamento`) e emiti-los (`emitirFatura`);
- corrigir faturas emitidas por nota de crédito (`criarNotaCredito`, `anularFatura`) ou de débito (`criarNotaDebito`) e emiti-las (`emitirNota`);
- emitir recibos (`emitirRecibo`, `emitirRegularizacao`) quando o tipo documental estiver fiscalmente suportado;
- abater um adiantamento numa fatura final (`abaterAdiantamento`);
- consultar saldo e resumo de pagamentos (`saldoEmCentimos`, `resumoDaFatura`);
- obter a taxa de IVA padrão da empresa (`taxaIvaPadrao`).

Todos os valores monetários, quantidades e taxas entram como texto decimal ou inteiro, nunca como `float`.

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

### Taxa de IVA

A taxa de IVA padrão é uma preferência fiscal **de cada empresa**. Os módulos obtêm-na por `FaturacaoGateway::taxaIvaPadrao($empresaId)` e nunca leem `config('faturacao.taxa_iva_geral')` diretamente. Estado: o Atelier e o Estúdio ainda leem a configuração global (ver `docs/auditoria-fase3-faturacao.md`, itens A-01 e A-02).

## Limitações fiscais explícitas

- **Adiantamentos**: o tipo `FA` e o abatimento na fatura final já existem no gateway (`criarRascunhoAdiantamento`, `abaterAdiantamento`), mas os fluxos de sinal do Atelier (recibo sem fatura associada) e do Estúdio (sinal emitido como FT) ainda não os usam, por isso não podem ser considerados prontos para submissão AGT. A validação formal da relação FA/fatura final pela AGT continua por demonstrar.
- **Recibos**: o model actual emite `RC`, cujo payload exige linhas. O modelo ainda não guarda essa estrutura. `paymentReceipt` é reservado pela especificação AGT para `AR` e `RG`; por isso, a emissão com AGT activa está bloqueada até existir mapeamento documental correcto.
- **Homologação**: testes locais e CI não substituem a certificação/homologação formal do software pela AGT.

## Regra de evolução

Alterações à API pública devem ser compatíveis com os módulos consumidores e acompanhadas de testes de integração que cubram Faturação, Atelier, Estúdio e o isolamento entre empresas.
