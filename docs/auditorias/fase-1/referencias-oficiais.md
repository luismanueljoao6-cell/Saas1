# Referências oficiais e técnicas — Fase 1

**Datas de consulta:** 2026-11-17 e 2026-11-18  
**Commit analisado:** `87c854322e255e79195fcd20c8ae7df881f79fbf` (head da PR #58 nesta data)  
**Regra:** só se regista aqui o que foi efectivamente consultado. Nada é inferido nem inventado.

Hierarquia aplicável (missão da Fase 1): 1) legislação angolana; 2) especificações e documentação oficial da AGT; 3) repositórios oficiais da AGT; 4) testes e comportamentos comprovados no SaaS1; 5) referências comerciais.

## 1. Organização GitHub «AGT-Angola» — NÃO VERIFICADA

Tentativas em 2026-11-17:

- `GET https://github.com/AGT-Angola` → erro de cliente (página não devolvida).
- API de pesquisa do GitHub, consulta `org:AGT-Angola` → «os recursos não existem ou não há permissão para os ver».

Consequência: **nenhum repositório, versão, endpoint ou campo foi registado a partir desta origem.**  
Acção pendente (decisão do responsável): confirmar o URL exacto da organização, ou confirmar que a AGT não publica repositórios no GitHub. Enquanto isso, a fonte de nível 2 é a documentação do Quiosque AGT (secção 2).

## 2. Documentação oficial AGT — herdada, a reverificar

Citada na decisão técnica `decisao-alocacoes-paymentReceipt.md` (2026-11-16); **não foi reconsultada nesta sessão**:

- Serviço «Registar Factura»: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_solicitar_registo.html
- Serviço «Consultar Factura»: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_consultar.html

Estado: **reverificar antes de usar como evidência** em `matriz-requisitos-agt.md` (versão, data e excerto por requisito).

## 3. XSD do SAF-T (AO) — fonte secundária de terceiros

| Campo | Valor |
|---|---|
| Repositório | https://github.com/assoft-portugal/SAF-T-AO (terceiro, **não é da AGT**) |
| Licença | MIT |
| Afirmação do README | «XSD Oficial do Governo de Angola para uso no SAF-T AO» |
| Ficheiro | `XSD/SAFTAO1.01_01.xsd` (125 559 bytes) |
| Blob SHA (git) | `7ed8c7ef3f013b035e7f1bfdd98359b0a15bb610` |
| Commit do repositório | `ed86c7d5a5ab21cf5ebb410507a5de04a86663f3` |
| targetNamespace | `urn:OECD:StandardAuditFile-Tax:AO_1.01_01` |
| Versão declarada no XSD | `1.01_01` |

O hash foi **reconfirmado em 2026-11-18** descarregando o ficheiro do commit fixado e comparando com `git hash-object`.

Classificação: **nível 5 até ser confrontado com o ficheiro publicado pela AGT.** A origem é uma afirmação do próprio repositório de terceiros. Serve para validação técnica provisória; **não** prova conformidade nem aceitação pela AGT.

## 4. Estado da validação XSD no SaaS1 (evidência no código)

- `Modules/Faturacao/config/config.php`: `saft_xsd_path` aponta para `storage/app/faturacao/saft/SAF-T-AO.xsd`. Esse ficheiro **não está no repositório** (a pasta `storage/app/faturacao/` é ignorada pelo Git).
- `Modules/Faturacao/tests/Feature/SafTValidadorXsdTest.php`: usa XSDs **sintéticos** gerados no teste (`xs:anyType`, `xs:string`). Nenhum teste validava um SAF-T gerado pelo exportador contra o XSD real.
- `SafTExportService` descreve-se como «ESTRUTURA PROVISÓRIA: não validada contra o XSD oficial».
- O `README.md` raiz lista «Validação do SAF-T (AO) contra o XSD oficial da AGT» como passo por fazer.

**Conclusão:** o requisito «validar os ficheiros contra os schemas oficiais» **não está comprovado**. Estado: PENDENTE. Ver `saft-ao.md`.

## 5. Evidência de execução da CI (head `87c8543`)

Fonte: execuções de verificação da PR #58 (API do GitHub, 2026-11-17).

| Job | Resultado |
|---|---|
| `qualidade` | success (terminou 11:52:06Z) |
| `testes-mysql` | success (terminou 11:56:13Z) |
| `composer-audit` | success (terminou 11:51:25Z) |

Limitações conhecidas (leitura de `.github/workflows/ci.yml`):

- A quantidade de testes executados **não foi obtida** (o registo dos jobs não estava acessível). Não declarar contagens sem evidência; usar `bash fase1-bloco1-saft-xsd.sh --suite` para a obter.
- O Pint corre **só nos ficheiros PHP alterados** pela PR (lint incremental).
- Os dois jobs principais excluem o grupo `auditoria` e executam-no por lista de nomes. Os 10 testes de `AuditoriaSubscricoesTest` estavam todos listados, mas `test_servico_recusa_retoma_de_renovacao_de_subscricao_vencida` corria **só em SQLite**; o Bloco 1 acrescenta-o ao passo MySQL.
- Estes resultados vêm exclusivamente da CI do GitHub: a auditoria estática não executou testes.

## 6. Referência comercial (Vendus)

Não consultada. Quando for, registar só como comparação de boas práticas (nível 5), nunca como regra fiscal.
