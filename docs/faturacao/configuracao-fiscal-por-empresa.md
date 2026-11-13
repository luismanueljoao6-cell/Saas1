# Configuração fiscal por empresa

## Motivo da implementação

O Saas1 é multiempresa. Uma taxa padrão, regime de IVA ou seleção de motivos de isenção guardados apenas em `config/faturacao.php` são globais e, por isso, não representam corretamente empresas com enquadramentos fiscais diferentes. As preferências operacionais passam a ser guardadas em `empresas.configuracoes.faturacao`, isoladas pelo `empresa_id`.

## Dados guardados

- `regime_iva`: preferência declarativa (`geral`, `simplificado`, `exclusao` ou `transitorio`).
- `taxa_iva_padrao`: taxa inicial dos formulários, limitada às taxas autorizadas pela configuração global `faturacao.taxas_iva_permitidas`.
- `motivos_isencao_ativos`: códigos do catálogo central que a empresa pretende disponibilizar nos formulários.

## Onde é aplicado

- Core > Definições da empresa: edição por utilizador com papel Administrador da própria empresa.
- Faturação > Faturas: taxa padrão e motivos disponíveis resolvidos para o tenant.
- Faturação > Notas de crédito/débito: lista de motivos filtrada pela empresa.
- Serviços de domínio: validação central das linhas confirma que o motivo pertence ao catálogo e está ativo para a empresa.
- FormRequests: validação de formato e de códigos permitidos; os serviços continuam a ser a última barreira.

## Segurança e integridade

- A configuração é guardada no JSON da própria empresa; não há tabela global nem cache partilhada entre tenants.
- O catálogo legal é central e não pode ser editado pelas empresas.
- Uma taxa padrão que deixe de constar de `taxas_iva_permitidas` é ignorada e regressa ao padrão global.
- A ativação de um motivo na interface não prova que a operação concreta beneficia de isenção. A empresa continua responsável por escolher o fundamento legal correto.
- A confirmação da tabela para comunicação AGT continua controlada por `FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS`; esta funcionalidade não ativa homologação nem comunicação fiscal.
- O regime é, nesta fase, um registo declarativo e não altera automaticamente a liquidação, a taxa, a elegibilidade legal, o SAF-T ou o enquadramento AGT. A automatização dessas regras exige regras fiscais formalmente validadas e versionadas.

## Próximos passos antes de produção

1. Confirmar a lista de taxas e os regimes vigentes com a AGT/contabilista.
2. Definir regras fiscais versionadas por data de vigência, sem reescrever documentos já emitidos.
3. Acrescentar testes de autorização HTTP, atualização concorrente das configurações e isolamento entre empresas.
4. Só depois ligar regras automáticas de regime à emissão e ao SAF-T, com testes de homologação AGT.
