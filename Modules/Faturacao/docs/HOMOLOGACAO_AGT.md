# Homologação com a AGT — checklist e evidências

Os testes automáticos usam respostas simuladas da AGT (`Http::fake`): provam a lógica
local, **não** a integração real. A conformidade só se pode afirmar depois de
executados, no ambiente de **homologação** (`sifphml.minfin.gov.ao`), os cenários abaixo,
com evidência guardada. Nada nesta lista está dado como feito.

Pré-requisitos: `faturacao:verificar-chaves` a passar; credenciais de produtor;
software registado na AGT; empresa de teste aderente; séries pedidas
(`php artisan faturacao:agt-solicitar-serie <empresa> FT|NC|ND|RC`).
Com a tabela de motivos de isenção por confirmar, as isenções ficam bloqueadas com a
AGT ativa (ver README, "Fase 2 da auditoria"); as notas de débito seguem sem bloco `taxes`
(ou como NS, se definires `FATURACAO_AGT_ND_CODIGO_NS`).

## Cenários

Para cada linha guarda: data/hora, `documentNo`, `requestID`, estado final e a resposta
da AGT (captura ou JSON). Corre `php artisan faturacao:agt-sincronizar` para enviar/consultar.

| # | Cenário | Esperado | Evidência |
|---|---|---|---|
| 1 | Pedir série de cada tipo (FT, NC, ND, RC) | série criada; numeração `{tipo} {código}/{n}` | |
| 2 | Estender uma série existente | mesma série, limite maior | |
| 3 | FT com IVA 14% | `valido` | |
| 4 | FT com linha isenta (ISE + `taxExemptionCode`) | `valido` — confirma o código na tabela | |
| 5 | FT com linha não sujeita (NS) | `valido` | |
| 6 | NC total e NC parcial sobre a FT 3 | `valido`; soma dentro do remanescente (E41/E42) | |
| 7 | ND sem imposto: (a) sem bloco `taxes` (por omissão); (b) como NS com `FATURACAO_AGT_ND_CODIGO_NS` | `valido` — confirma qual das duas a AGT aceita e fica só com essa | |
| 8 | RC sobre uma FT | `valido` | |
| 9 | Documento inválido de propósito (ex.: total incoerente, E21–E24) | `invalido`; erros guardados | |
| 10 | Correção do 9: `faturacao:agt-corrigir <submissão>`, rever, emitir | novo número, `documentStatus` C + `rejectedDocumentNo`; `valido` | |
| 11 | Reenviar o mesmo `documentNo` rejeitado | recusado (E46) — confirma que o sistema não o tenta | |
| 12 | Pedido repetido (mesmo `submissionUUID`) | devolve o `requestID` original, sem duplicar | |
| 13 | AGT indisponível / timeout a meio de um lote | submissões ficam `pendente`/`erro`, sem duplicados ao retomar | |
| 14 | `faturacao:verificar-cadeia --agt` | sem problemas; avisos só para o que não estiver `valido` | |

## Antes de produção

- [ ] Todos os cenários acima feitos em homologação, com evidência.
- [ ] Tabela de motivos de isenção comparada com o Anexo 6.4 oficial e `FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true`.
- [ ] Decidido, e confirmado em homologação, se a ND segue sem `taxes` ou como NS (`FATURACAO_AGT_ND_CODIGO_NS`).
- [ ] Número de certificação do software real em `FATURACAO_SAFT_NUMERO_CERTIFICADO`.
- [ ] XSD oficial do SAF-T em `FATURACAO_SAFT_XSD_PATH` e exportações a passar na validação.
- [ ] Migrações e testes corridos na base de dados de produção (MySQL), incluindo concorrência de emissões.
- [ ] Monitorização do `faturacao:agt-sincronizar`, cópias de segurança testadas, `faturacao:verificar-chaves` limpo.
