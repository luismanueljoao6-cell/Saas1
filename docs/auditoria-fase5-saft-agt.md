# Auditoria da Fase 5 — SAF-T Angola e comunicação com a AGT

**Base:** `main` @ `bfde8eb` (PR #36). **Método:** leitura estática de `AgtClient`, `AgtSubmissaoService`, `AgtSerieService`,
`AgtCorrecaoService`, `AgtDocumentoBuilder`, `AgtAssinaturaService`, `AssinaturaFiscalService`, `SafTExportService`,
`SafTPreValidacao`, `SafTValidadorXsd`, `GerarSafTJob`, `ReciboService`, config, migration `agt_submissoes`, agendador e
`docs/homologacao-agt.md`. Nada foi executado contra a AGT. **Este documento não afirma conformidade fiscal.**

Estados: **CORRIGIDO** (neste PR) · **DECISÃO** (precisa de escolha de desenho) · **EVIDÊNCIA** (só XSD/AGT/certificação resolvem).

| ID | Prio | Achado | Ficheiros | Estado |
|---|---|---|---|---|
| F5-01 | P0 | SAF-T provisório: sem XSD oficial, namespace por confirmar, `Payments` provisório (H1/H2) | `SafTExportService`, `SafTValidadorXsd` | EVIDÊNCIA |
| F5-02 | P0 | O campo `Hash` recebe o JWS completo; pode exceder o limite do esquema anterior | `SafTExportService` | EVIDÊNCIA |
| F5-03 | P0 | Elementos que o exportador não emite e que podem ser obrigatórios no XSD (a confirmar): `TaxExemptionReason` (a `mencao` já existe na config), estrutura de `CompanyAddress`, `ProductType`/`ProductGroup`, nome do campo do nº de certificação | `SafTExportService` | EVIDÊNCIA |
| F5-04 | P0 | Abatimentos de adiantamentos sem representação fiscal (H3); a recusa em produção é a salvaguarda correta | `SafTPreValidacao` | EVIDÊNCIA |
| F5-05 | P0 | Com a AGT ativa, `ReciboService::emitir` (RC) lança `DomainException` e o builder só aceita AR/RG: o caminho padrão de recibos fica fechado; só RG com fatura de origem passa | `ReciboService`, `AgtDocumentoBuilder` | DECISÃO |
| F5-06 | P1 | `falhaTransitoria` soma 1 tentativa por execução (minuto a minuto) e ao fim de `max_tentativas` marca ERRO: ~5 min de indisponibilidade obrigam a reenvio manual de tudo. Pede backoff por documento (coluna `proxima_tentativa_em`) | `AgtSubmissaoService` | CORRIGIDO (backoff 1, 2, 4… min até 30 min; 8 tentativas) |
| F5-07 | P1 | Contingência: a série aceita o indicador `C`, mas não há fluxo de emissão em contingência nem reconciliação posterior | `AgtSerieService` | DECISÃO |
| F5-08 | P1 | `obterEstado` com HTTP 400, E95 ou `resultCode` 9 marca ERRO **com** requestID: fica fora de `aguardamIntervencao()`, de `reenviar()` e da consulta — sem caminho operacional | `AgtSubmissaoService` | DECISÃO |
| F5-09 | P1 | Chave de emissor em falta numa empresa abortava a consulta de estados de todas (`AssinaturaFiscalException` não tratada); o comando terminava com stack trace | `AgtSubmissaoService`, `SincronizarAgtCommand` | CORRIGIDO |
| F5-10 | P1 | Critérios de ambiente inconsistentes: SAF-T, abatimentos e isenções usam `app()->isProduction()`; a AGT usa `agt.ambiente` | `SafTExportService`, `AgtClient` | DECISÃO |
| F5-11 | P2 | Pedidos «a aguardar intervenção» consultados de 60 em 60 s indefinidamente | `AgtSubmissaoService` | CORRIGIDO (1 h, configurável) |
| F5-12 | P2 | Depois de a AGT aceitar, o lote era atualizado documento a documento, sem transação | `AgtSubmissaoService` | CORRIGIDO |
| F5-13 | P2 | Pré-validação e geração do SAF-T liam a BD em momentos distintos | `GerarSafTJob` | CORRIGIDO (um só instantâneo) |
| F5-14 | P2 | A correção de fatura rejeitada perdia `operation_type` e `preco_unitario_base` | `AgtCorrecaoService` | CORRIGIDO |
| F5-15 | P2 | Agendador: `withoutOverlapping()` com mutex de 24 h, sem `onOneServer`, sem alerta de falha | `routes/console.php` | CORRIGIDO |
| F5-16 | P2 | Série AGT: intervalo devolvido não era validado (CORRIGIDO); pedidos concorrentes sem idempotência e extensão que sobrescreve `quantidade_autorizada` (a confirmar) | `AgtSerieService` | CORRIGIDO + DECISÃO |
| F5-17 | P2 | Um único lote (30) por empresa por execução: no máximo 30 documentos/min/empresa | `AgtSubmissaoService` | DECISÃO |
| F5-18 | P2 | `MasterFiles` do SAF-T lê Cliente/Produto atuais e não o snapshot congelado nos documentos | `SafTExportService` | DECISÃO |
| F5-19 | P2 | SAF-T construído em DOM em memória e validado sobre a string completa; sem streaming | `SafTExportService`, `SafTValidadorXsd` | DECISÃO |
| F5-20 | P3 | `request_id` é `varchar(15)`: se a AGT devolver mais, a escrita falha depois de aceite e o reenvio repete-se (E09) | migration `agt_submissoes` | EVIDÊNCIA |
| F5-21 | P3 | `GerarSafTJob`: rotação para `historico/` e escrita do novo ficheiro não são atómicas; gerações no mesmo segundo colidem | `GerarSafTJob` | DECISÃO |

H7 (ensaio completo em homologação da AGT: séries, submissão, rejeições, correções) continua em aberto e não é substituído por nenhum teste interno.

## O que este PR altera
F5-09, F5-11, F5-12, F5-13, F5-14, F5-15 e a validação do intervalo de F5-16. Novo teste: `AgtRobustezFase5Test`.
Nova chave de configuração: `faturacao.agt.consulta_intervalo_intervencao` (`FATURACAO_AGT_CONSULTA_INTERVALO_INTERVENCAO`, 3600 s).

## O que fica fora e porquê
Os itens DECISÃO mudam comportamento fiscal ou exigem migration; os itens EVIDÊNCIA dependem do XSD oficial, da AGT ou da certificação.
Não se implementam funcionalidades novas antes de fechar todas as fases.
