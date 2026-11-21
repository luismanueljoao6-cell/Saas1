# Pendências e decisões — Fase 1

**Data:** 2026-11-18 · Nada aqui é decisão tomada: são pontos que exigem documento oficial ou decisão do responsável. O comportamento fica preventivamente bloqueado onde indicado.

| ID | Pendência | Evidência | Documento / decisão necessária | Estado |
|---|---|---|---|---|
| P-01 | Organização GitHub «AGT-Angola» não verificável | Tentativas de 2026-11-17 (`referencias-oficiais.md`) | URL oficial exacto, ou confirmação de que a AGT não publica no GitHub | Aberta |
| P-02 | Documento fiscal de recebimento de uma FA (AR, RG ou outro) | `decisao-alocacoes-paymentReceipt.md`, secções 10, 11 e 13; pontos H3 e H7 de `docs/homologacao-agt.md` | Especificação AGT completa e ensaio em homologação | Bloqueada — RG/RC mantidos bloqueados com AGT activa |
| P-03 | Regra de rateio/arredondamento de `paymentReceipt` por linha (várias taxas, descontos, NC) | `decisao-alocacoes-paymentReceipt.md`, secção 4 | Especificação AGT e casos de homologação | Bloqueada |
| P-04 | Valores obrigatórios do SAF-T sem origem definida: `TaxEntity`, `ProductCompanyTaxID`, `SoftwareValidationNumber`, `AccountID`, `SelfBillingIndicator`, `ProductType`, `ProductNumberCode`, estrutura de morada | `saft-ao.md`, secção 2 | Especificação técnica SAF-T (AO) da AGT | Aberta |
| P-05 | O XSD usado é de terceiros | `referencias-oficiais.md`, secção 3 | Confrontar com o XSD publicado pela AGT (hash e versão) | Aberta |
| P-06 | `subscricoes` e `pagamentos` (Subscrições) mantêm `ON DELETE CASCADE` a partir de `empresas`; as tabelas fiscais já foram passadas a RESTRICT | Migrations `2026_02_01_000002/3` vs `2026_10_09_000010` e `2026_11_10_000025`; `Empresa` usa `SoftDeletes` | Decidir se o histórico de cobranças da plataforma deve ficar protegido como o fiscal | Aberta |
| P-07 | Atelier: sinal emitido como RC sem FA; pagamento final bloqueado para sinais legados | Corpo da PR #58 e secção 14 da decisão | Depende de P-02 | Bloqueada |
| P-08 | Estúdio: sinal de pacote ainda emitido como FT (FA exige P-02) | Secções 9, 19 e 20 da decisão | Depende de P-02 | Bloqueada |
| P-09 | Pint só corre nos ficheiros alterados | `ci.yml` | Decidir se a dívida de formatação histórica se paga numa PR dedicada | Aberta |
| P-10 | Contagem de testes executados na CI não registada | `referencias-oficiais.md`, secção 5 | Correr `bash fase1-bloco1-saft-xsd.sh --suite` e registar o resumo | Aberta |
| P-11 | Faltam testes de concorrência real (MySQL, vários processos) para NC, recebimentos, adiantamentos/abatimentos, pagamentos, subscrições, pedidos e faturação de projectos | O grupo `concorrencia-real` só cobre emissão/numeração e reenvio de campanha; `AbatimentoIdempotenciaConcorrenteTest` simula a corrida e declara que não substitui processos paralelos reais | Implementar sobre o modelo de `EmissaoConcorrenciaRealTest`; só conta como prova em MySQL | Aberta — próximo bloco |
