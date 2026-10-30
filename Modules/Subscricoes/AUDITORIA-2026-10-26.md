# Auditoria do módulo Subscricoes — 26/10/2026

Âmbito: `Modules/Subscricoes` (modelos, serviços, jobs, comandos, rotas, views,
migrations e testes) e a integração com o Core (`Empresa`, `TenantService`,
`VerificarSubscricaoAtiva`).

Contrato ProxyPay v2 conferido com a documentação oficial: assinatura
`X-Signature` (HMAC-SHA-256 do corpo bruto com a API key), `amount` como texto,
`end_datetime` ISO 8601, `custom_fields` só com texto e `DELETE /references/{id}`.
Sem divergências.

## Corrigido (fix-subscricoes-auditoria.sh)

1. Renovação: `alternarRenovacao` só atua em subscrições ativas e a
   `DomainException` deixa de dar erro 500. `cancelarRenovacao` recusa estados
   não ativos (antes, numa subscrição pendente, expirava o primeiro pagamento e
   cancelava a referência no gateway).
2. `iniciar`: as mensagens de negócio (plano indisponível, pagamento pendente
   para outro plano, pedido em preparação) chegam ao utilizador em vez do erro
   genérico.
3. Pedidos de pagamento sem referência, abandonados quando o processo morre a
   meio, bloqueavam a empresa até 3 dias. Passam a ser descartados ao fim de
   2 min em `iniciar`/`gerarRenovacao` (`SUBSCRICOES_PREPARACAO_ABANDONADA_MINUTOS`)
   e ao fim de 15 min pela reconciliação.
4. A reconciliação contava 2 tentativas por reprocessamento (comando + job).
5. `PagamentoService::confirmar`: uma lista de serviços sem nenhum serviço
   conhecido já não impede a confirmação de um pagamento recebido; uma
   subscrição reativada deixa de ficar com `cancelada_em`; docblock do valor.
6. Job diário: falhar ao preparar o serviço de renovações já não salta o
   heartbeat lido por `subscricoes:saude`.
7. Provider: o agendamento só é registado em consola, não em cada pedido HTTP.
8. Views: aviso de erro duplicado em `/planos`; botão "Subscrever" escondido a
   quem não é administrador; preço com decimais; valores copiados com `@js`;
   a página de pagamento pendente atualiza-se sozinha a cada 30 s.
9. `planos:preco` rejeita preços que a ProxyPay não aceita (0,01 a 99 999 999,99;
   no máximo 2 decimais; sem notação científica).
10. Índices para a rotina diária e a reconciliação.

## Não alterado (decisão tua)

- `pagamentos` e `subscricoes` têm `cascadeOnDelete` para a empresa: um
  `forceDelete` apaga registos financeiros (a `Empresa` usa SoftDeletes, por isso
  só afeta a eliminação definitiva).
- `pagamentos.referencia_externa` é única entre gateways. Ao juntar um segundo
  gateway, passar a única por (`gateway`, `referencia_externa`).
- `cancelarReferencia` não faz parte da interface do gateway (usa-se
  `method_exists`): um gateway novo sem esse método deixa de cancelar
  referências sem avisar.
- Ao pagar o primeiro plano, os dias que restavam do trial perdem-se (só se
  somam dias de uma subscrição 'ativa').
- `TenantService::suspender` recomeça a tolerância na data da suspensão (rotina
  das 02:00) e não na data de fim: a tolerância real pode ser até ~1 dia maior
  do que `core.periodo_tolerancia_dias`.
- O layout do Core carrega o Alpine por CDN sem versão fixa nem SRI.
- Um pagamento superior ao devido é rejeitado (falta política de crédito ou
  reembolso).
- `LimitesPlanoService` consulta a base de dados em cada chamada.
