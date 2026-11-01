# Auditoria do módulo EstudioMusica

Auditoria completa (código, base de dados, portal, faturação, jobs, vistas), feita lendo o módulo
contra o código real do Core e do Faturação e **executando** a suite — não só por leitura. Cada
correção tem pelo menos um teste que falha no código anterior.

## Corrigido

| # | Gravidade | Problema | Correção |
|---|-----------|----------|----------|
| 1 | Crítica | Portal: `reproduzir`, `descarregar`, `comentar`, `aprovar` e `solicitarAjustes` davam **erro 500**. O Laravel passa os parâmetros de rota por posição; a rota tem `{token}` e `{versao}` mas os métodos só declaravam `$versao` (3 testes já falhavam). | Os métodos declaram `string $token` antes de `$versao`. |
| 2 | Alta | Depois de o cliente tocar o áudio, comentar/aprovar/aceitar termos redirecionava-o **para o ficheiro de áudio** em vez do portal (`back()` + `Referrer-Policy: no-referrer` → usa o último GET da sessão, que é o stream). | Todas as respostas do portal vão explicitamente para a página do portal. |
| 3 | Alta | O player servia o **original** a projetos quitados mesmo sem o cliente ter aceitado os Termos (bastava «guardar áudio como…»). | O original só sai com projeto quitado **e** termos aceites; senão, a pré-visualização. |
| 4 | Alta | Notas de crédito ignoradas: anular um sinal/saldo deixava uma «dívida fantasma», impedia de refaturar («já foi emitido» / «sem saldo») e podia deixar o projeto impossível de quitar (o ReciboService recusa receber acima de total − créditos). | `ProjetoMusical` calcula faturado/devido líquido de notas de crédito; o sinal/saldo anulados podem ser emitidos outra vez. |
| 5 | Alta | Só contavam como pagos os recibos ligados pela tabela do Estúdio: um pagamento registado no ecrã de Recibos do Faturação **não quitava** o projeto. | Conta também qualquer recibo emitido contra uma fatura do projeto (sem contar duas vezes). |
| 6 | Alta | Sessões criadas pelo ecrã nunca tinham **projeto** (o formulário não o oferecia): a cobrança por hora nunca chegava ao projeto e este nunca ficava quitado. Também faltava engenheiro. | O formulário tem projeto e engenheiro; abre pré-selecionado a partir da página do projeto. |
| 7 | Alta | Erros da faturação (limite mensal de faturas do plano, subscrição inativa) davam **500** em sinal, saldo e check-out; no check-out o rollback fazia a sessão ficar «em curso» e, ao repetir mais tarde, o cliente pagava as horas entretanto passadas. | Sinal/saldo mostram o motivo. O check-out grava sempre o fim real; se a fatura não sair, aviso claro e botão **Faturar** (idempotente) na sessão. |
| 8 | Média | Horas faturadas em milésimos (20 min = 0,333 h → perda de cêntimos) e cupão aplicado ao preço unitário. | Cobrança calculada ao cêntimo a partir dos minutos; cupão sobre o total; todo o dinheiro em `Dinheiro` (cêntimos), sem `float`. |
| 9 | Média | Sessão de um projeto **por pacote apagado** era faturada à hora (a relação ignorava projetos apagados). | `withTrashed()` em `SessaoEstudio` e `FaixaMusical`. |
| 10 | Média | Erros de validação **não apareciam** em quase nenhum ecrã (o layout do Core só mostra `sucesso`/`erro`): formulários inválidos falhavam em silêncio. | Partial `partials/erros` em todos os ecrãs do staff e no portal. |
| 11 | Média | `preco_hora` aceitava valores que a coluna `decimal(10,2)` não aguenta (erro 500); `ativa` sem `sometimes`; e **não havia como editar/desativar uma sala**. | Limites corrigidos; edição inline (nome, preço, descrição, ativa). |
| 12 | Média | Sessões sem limite de duração (um ano errado bloqueava a sala meses); projeto apagado/cancelado aceite; sem cancelar/reagendar no ecrã. | Máximo configurável (`sessao_duracao_max_horas`, 24); só projetos ativos do cliente; botões Cancelar e Reagendar (`reagendar()` já existia no serviço, sem rota). |
| 13 | Média | A landing só mostra projetos marcados como destaque, mas **não havia ecrã** para os marcar. | Cartão «Portfólio na página pública» (só Administrador), link https obrigatório e confirmação da autorização do artista. |
| 14 | Média | Lembretes e campanhas varriam **todas** as empresas, incluindo estúdios que já cancelaram o serviço ou com subscrição expirada. | Só empresas com o serviço Estúdio ativo e acesso (`EmpresasDoEstudio`). |
| 15 | Média | Se o canal WhatsApp/SMS **lançava exceção**, o job rebentava antes de tentar o e-mail e as repetições falhavam sempre no mesmo ponto. | Exceção do canal conta como «não entregou»; o e-mail e o SMS continuam a ser tentados. |
| 16 | Média | A pré-visualização (ffmpeg, até 3 min) gerava-se **dentro do pedido HTTP** do primeiro «play», com vários pedidos Range a lançar vários ffmpeg. | Gerada logo após o upload por job; lock por versão como rede de segurança. |
| 17 | Baixa | `MarcadorAudio::tempoFormatado()`: `float % int` → aviso de depreciação no PHP 8.1+. | Segundos inteiros primeiro. |
| 18 | Baixa | Converter pedido de orçamento em cliente: sem lock (duplicados) e o consentimento de marketing passava para um cliente achado só por **telefone** (pode ser outra pessoa). | Lock; o consentimento só passa se o e-mail coincidir. |
| 19 | Baixa | Ficheiro em falta no disco → 500 (`response()->file`/`download`). | 404 limpo. |
| 20 | Baixa | Lembrete de aprovação para qualquer versão (antiga ou já aprovada); sem botão no ecrã. | Só a versão mais recente pendente; botão na página do projeto. |
| 21 | Baixa | Publicar termos em simultâneo → erro de chave única; faixa acrescentada a projeto concluído/cancelado; `integrantes_banda` em branco recusava o formulário; percentagem do cupão «15.00%». | Lock por empresa; guarda de estado; `nullable`; «15%». |
| 22 | Baixa | Job de lembretes sem índice (varre a tabela de todas as empresas de hora a hora). | Migration `(estado, inicio_previsto)`. |

## Não alterado (decisões para ti)

- **Landing por id numérico** (`/estudio/{id}`) permite enumerar estúdios; o `slug` em `empresas` pertence ao Atelier.
- **Estado `confirmada`** nunca é atribuído; não há ação «confirmar sessão».
- **Editar projeto** depois de criado (prazo, valor do pacote, % do sinal, produtor/engenheiro) continua sem ecrã.
- **`criado_por_cliente = false`** (nota interna) é mostrado no portal: hoje nada cria essas notas; se passar a existir, filtrar no portal.
- A conversão de pedido→cliente continua a juntar por e-mail ou telefone idênticos (texto exato; sem normalizar números).
