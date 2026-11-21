# SAF-T (AO) — estado da conformidade (Fase 1)

**Estado: PENDENTE — conformidade não comprovada.**  
**Data:** 2026-11-18 · **Commit analisado:** `87c8543`

**Método:** leitura estática do `SafTExportService` (cabeçalho, `Customer`, `Product`) comparada com a estrutura do XSD `SAFTAO1.01_01` (fonte de terceiros, nível 5 — ver `referencias-oficiais.md`). Esta comparação **não gerou nem validou nenhum ficheiro**. A validação efectiva é feita pelo teste `SafTXsdOficialTest` (secção 3).

## 1. O que o exportador declara

`SafTExportService` descreve-se como «ESTRUTURA PROVISÓRIA: ainda NÃO validada contra o XSD oficial da AGT». O ficheiro gerado leva um comentário XML a dizê-lo.

## 2. Divergências previstas (a confirmar pelo teste)

| Elemento | Exportador (`87c8543`) | XSD `1.01_01` | Consequência prevista |
|---|---|---|---|
| `AuditFileVersion` | `1.0` | padrão `1.01_01` | falha de validação |
| Ordem no `Header` | `TaxAccountingBasis` depois de `DateCreated` | logo a seguir a `TaxRegistrationNumber` (sequência obrigatória) | falha de validação |
| `CompanyAddress` | texto simples | `AddressStructureAO`: `AddressDetail`, `City` e `Country` obrigatórios | falha de validação |
| `TaxEntity` | ausente | obrigatório | falha de validação |
| `ProductCompanyTaxID` | ausente | obrigatório | falha de validação |
| `SoftwareValidationNumber` | ausente (escreve `SoftwareCertificateNumber`, que não existe no XSD) | obrigatório; padrão `\d+/AGT/\d{4}` ou `0` | falha de validação |
| `ProductID` | `Saas1` | padrão `[^/]+/[^/]+` | falha de validação |
| `Customer` | `CustomerID`, `CustomerTaxID`, `CompanyName`, `Address` | exige também `AccountID`, `BillingAddress` (estrutura) e `SelfBillingIndicator`; `Address` não faz parte da sequência | falha de validação |
| `Product` | `ProductCode`, `ProductDescription` | exige `ProductType` (primeiro) e `ProductNumberCode` | falha de validação |

`SourceDocuments` (faturas, notas, pagamentos) **ainda não foi comparado**: fica a cargo do relatório do teste.

## 3. Prova automática

`Modules/Faturacao/tests/Feature/SafTXsdOficialTest.php`:

1. Confirma que o XSD versionado em `tests/Fixtures/saft/` tem o hash git esperado.
2. Emite FT a 14 %, FT isenta (M13), FT não sujeita (M02) e ND; gera o SAF-T com o exportador real; valida com `SafTValidadorXsd` contra o XSD real.
3. **Falha** (nunca salta) se o XSD não existir ou o XML não validar.

O teste só entra no repositório quando passa. Enquanto falhar, o relatório fica em `storage/logs/saft-xsd-probe-resultado.txt` e o XML gerado em `storage/logs/saft-xsd-probe.xml`.

## 4. Valores que não se inventam

Estes campos exigem valores definidos pela especificação/AGT; não se preenchem por suposição (ver `pendencias-e-decisoes.md`, P-04):

- `TaxEntity`, `ProductCompanyTaxID`, `SoftwareValidationNumber`;
- `AccountID` e `SelfBillingIndicator` de clientes;
- `ProductType` e `ProductNumberCode` de produtos;
- estrutura de morada (`AddressDetail`, `City`, `Country`) quando a empresa/cliente só tem texto livre.

## 5. Distinção obrigatória

XML válido contra o XSD **não** é submissão aceite nem homologação da AGT. Só a validação XSD com o ficheiro oficial da AGT e o ensaio no ambiente de homologação fecham este requisito.
