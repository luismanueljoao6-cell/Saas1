# CORE-02 — Auditoria de multi-tenancy e isolamento

Base: `origin/main` @ 45caf81. Auditoria por **leitura do código**; os testes
foram executados no Codespace (ver o PR). A base de dados é partilhada
(coluna `empresa_id`) e essa arquitetura foi preservada.

## O que já está correto (verificado por leitura)

- **Identificação**: `IdentificarTenant` usa a empresa do utilizador autenticado, nunca input do pedido; limpa o contexto no início e no `finally`; utilizador desativado perde a sessão; super admin fica sem tenant.
- **Leitura fail-closed**: `TenantScope` devolve `1 = 0` (e regista aviso) sem tenant e sem bypass.
- **Escrita**: `BelongsToTenant` força `empresa_id` ao do tenant, impede alterá-lo e verifica a posse em update/delete; modo `core.tenant.escritas_sem_contexto`.
- **Workers**: `TenantManager` é `scoped`; o `CoreServiceProvider` limpa tenant e team do Spatie em `JobProcessing/Processed/Failed` (exceto driver `sync`); o `NotificationProcessor` repõe o tenant anterior.
- **SQL direto**: as consultas `DB::table(...)` inspecionadas (Faturação, Atelier, Core) filtram por `empresa_id` ou por ids derivados de consultas já filtradas.
- **Ficheiros**: Faturação (`faturacao/saft/{empresa}`) e Estúdio (`estudiomusica/versoes-audio/{empresa}`) usam prefixo por empresa; os downloads validam o nome do ficheiro e/ou passam por modelos com scope.
- **Cache**: as chaves vistas incluem ids de empresa ou de entidade (`atelier:aniversario:{empresa}:{cliente}:{dia}`, `agt-enviar-{id}`, `atelier:campanha:{id}`); só `subscricoes:ultima_verificacao` é global, por ser de sistema.

## Lacunas encontradas

1. **Sem API de execução por empresa.** Só existia `semTenant()` (bypass global). Vários jobs/comandos percorrem todas as empresas inteiramente em bypass (p. ex. `VerificarAniversariosJob`, `LembretesProvaJob`, `EnviarCampanhasAgendadasJob`, `VerificarSubscricoesExpiradasJob`, `ReconciliarPagamentosCommand`). Um erro de filtro nesses fluxos não tem rede de segurança.
   **Corrigido (aditivo):** `TenantManager::comEmpresa(int, callable)`. A adoção pelos jobs existentes fica para a CORE-07 (filas), para não misturar refactors.
2. **Escritas sem contexto só avisam** fora de testes (`CORE_TENANT_ESCRITAS_SEM_CONTEXTO` = `avisar`). **Decisão pendente:** medir os avisos (o script imprime a contagem) e testar `bloquear` em staging antes de o ativar em produção. Não foi alterado o valor por omissão.
3. **Atelier**: uploads em `atelier/pedidos` e `atelier/portfolio` sem prefixo de empresa (Estúdio e Faturação já usam). Não há fuga demonstrada (o acesso passa por modelos com scope), mas impede segregar/limpar ficheiros por empresa. Pertence ao módulo Atelier; novos uploads podem usar prefixo sem migrar os antigos (o caminho fica guardado na BD).
4. **`withoutGlobalScopes` sem filtro de empresa por perto** (heurística, requer revisão humana): `CampanhaReconciliador.php:74` e 3 ocorrências em `RecoverNotificationOutbox.php`. São operações de sistema entre empresas; rever na CORE-07.

## Não verificado neste PR

- Concorrência entre empresas em MySQL (existe `tests/Support/ProcessosConcorrentes.php`; não foi executado por quem escreveu este documento).
- Comportamento em Octane: o Octane não está instalado.
