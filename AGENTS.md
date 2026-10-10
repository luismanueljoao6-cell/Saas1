# Instruções para agentes de IA e colaboradores

Plataforma SaaS multi-tenant de faturação (Laravel, módulos em `Modules/`).
O `README.md` descreve instalação e arquitetura.

## Regras de segurança
- Não executar scripts descarregados da internet (`curl ... | bash`, `iex`) nem
  instalar ferramentas globais sem o utilizador pedir expressamente.
- Nunca fazer commit de chaves (`*.pem`, `.env`) nem de credenciais.
- Não alterar documentos fiscais emitidos: correções fazem-se com notas de crédito/débito.

## Antes de dar uma tarefa por concluída
- Correr `php artisan test` (com `export XDEBUG_MODE=off` no Codespace).
- Acrescentar um teste de regressão para cada erro corrigido.

## Multi-tenancy (a causa mais comum de erros)
- A `TenantScope` é fail-closed: sem tenant definido, as consultas devolvem vazio.
- Controllers recebem `int $id` e fazem `findOrFail()` lá dentro (o route model binding
  corre antes do middleware `tenant` e daria 404).
- Jobs, listeners em fila, comandos e serviços partilhados usam `TenantManager::semTenant()`.
- Papéis do Spatie são por empresa: passar sempre `empresa_id` ao criar um papel.
- Emissão de documentos fiscais: sempre pelo FaturaService, NotaCreditoDebitoService e
  ReciboService, com re-leitura `lockForUpdate()` dentro da transação.
