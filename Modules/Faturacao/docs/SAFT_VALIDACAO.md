# Validação do SAF-T (AO) — fase 3

## O que está provado e o que não está

| Camada | Quem faz | Estado |
|---|---|---|
| Coerência interna dos documentos (totais, IVA, hash, numeração, códigos fiscais) | `SafTPreValidacao` | ✅ automática; bloqueia a geração |
| Conformidade estrutural com o XSD oficial | `SafTValidadorXsd` | ⛔ **por provar**: o XSD oficial não está no repositório |
| Aceitação pela AGT | homologação | ⛔ por fazer |

"Sem erros" na primeira camada **não** significa SAF-T (AO) válido.

## Como validar contra o XSD oficial

1. Obtém o XSD oficial nas especificações da AGT e guarda-o em
   `storage/app/faturacao/saft/SAF-T-AO.xsd` (ou define `FATURACAO_SAFT_XSD_PATH`).
2. Corre:

   ```bash
   php artisan faturacao:saft-validar <empresa_id> 2026-01-01 2026-12-31 --guardar=/tmp/saft.xml
   # ou, com outro XSD:  --xsd=/caminho/SAF-T-AO.xsd
   ```
3. O comando devolve erro se faltar o XSD, se falharem as regras fiscais ou se o XML não for
   conforme. Os erros do XSD indicam a linha do XML (`--guardar` para o inspecionar).

## Namespace

O `xmlns` do ficheiro é, por ordem: `faturacao.saft_namespace` (`FATURACAO_SAFT_NAMESPACE`) ou o
`targetNamespace` do XSD oficial, se existir. Sem nenhum dos dois, o ficheiro não leva namespace.
Assume-se `elementFormDefault="qualified"`; se o XSD oficial for `unqualified`, o erro aparece na validação.

## Estrutura ainda provisória (a fechar com o XSD)

O cabeçalho, os `MasterFiles` e os blocos por tipo de documento foram escritos sem o XSD à frente.
Os erros devolvidos pelo comando acima são a lista de trabalho para os alinhar. Pontos conhecidos:
`CompanyAddress` plano, elementos obrigatórios do cabeçalho eventualmente em falta, recibos
(`RC`/`RG`) ainda não exportados (fase 2), `InvoiceType` `FA` dentro de `SalesInvoices` (confirmar na enumeração do XSD).

## O que continua fora desta fase

Regimes de IVA, diferenciação por região, retenções na fonte e tabelas fiscais oficiais exigem texto legal
em vigor (Decreto Presidencial 71/25, Decreto Executivo 683/25) e confirmação do contabilista/AGT;
não foram inventados. Ver `MATRIZ_REQUISITOS_FISCAIS.md`.
