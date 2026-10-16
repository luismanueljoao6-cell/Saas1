# Conformidade com a lei angolana — Módulo Estúdio

> Guia técnico-organizacional, **não é aconselhamento jurídico**. Os pontos marcados **[ADVOGADO]**
> devem ser validados por um advogado angolano antes de entrarem em produção. Leis de referência:
> Lei n.º 22/11 (Proteção de Dados Pessoais), Lei n.º 23/11 (Comunicações Eletrónicas e Sociedade da
> Informação), Lei n.º 15/03 (Defesa do Consumidor), Decreto Presidencial n.º 234/16 (Livro de
> Reclamações), Lei n.º 15/14 (Direito de Autor e Direitos Conexos), Código do IVA (Lei n.º 7/19) e
> o regime das faturas (Decreto Presidencial n.º 71/25, tratado no módulo Faturação).

## 1. O que o módulo já faz

| Tema | Implementação |
|---|---|
| Consentimento de marketing | `clientes.aceita_marketing` (por omissão **não**). Campanhas, aniversário e follow-up só chegam a quem aceitou. Cada mensagem promocional leva uma ligação assinada para sair (`/estudio/{empresa}/marketing/sair/{cliente}`), que funciona mesmo que o serviço do estúdio seja desativado. |
| Dever de informar | Política de Privacidade pública (`/estudio/{empresa}/privacidade`), ligada ao formulário da landing. O formulário exige aceite expresso e guarda a data (`consentimento_dados_em`). |
| Direitos do titular | `php artisan estudiomusica:exportar-dados-artista {empresa} {cliente}` (acesso) e `estudiomusica:limpar-dados-artista {empresa} {cliente}` (eliminação). Os campos da ficha podem ser limpos pela interface (retificação). |
| Limitação da conservação | Job semanal apaga pedidos de orçamento > 12 meses e reclamações > 24 meses (`retencao_*_meses`). |
| Transferência internacional | WhatsApp (Meta) e SMS (Twilio) ficam **bloqueados** até existir `ESTUDIOMUSICA_APD_AUTORIZACAO` (ver 2.1). O e-mail e o canal `log` não são afetados. |
| Segurança | Portal por token (guardado em hash, expira, nunca em query string), cabeçalhos `no-store`/`no-referrer`/`noindex`, limite de pedidos por token, jobs com payload cifrado, números mascarados nos logs, ficheiros de áudio privados (pré-visualização até haver pagamento), leitura restrita a quem tem papel do estúdio. |
| Direito de autor / contrato | Termos de Serviço **versionados** (propriedade dos masters, material de terceiros, portfólio só com autorização). O cliente aceita no portal (data, IP em hash, browser) antes de aprovar ou descarregar. Administrador edita em *Termos e Privacidade*. |
| Consumidor | Preço/hora (sem IVA) visível na landing (`landing_mostrar_precos`); cupão de aniversário aplicado de facto nas faturas; canal digital de reclamações com resposta; aviso do Livro de Reclamações. |
| Fiscal | IVA correto no sinal/saldo (`valor_pacote` é sem IVA); recibos pela interface. A comunicação à AGT é do módulo Faturação. |

## 2. O que continua a exigir ação humana

### 2.1 APD — notificação/autorização e transferências **[ADVOGADO]**
1. Confirmar com o advogado se o tratamento tem de ser **notificado ou autorizado** pela APD (a Lei 22/11 prevê
   notificação prévia/autorização conforme o tipo de dados e de tratamento). Guardar o nº em `ESTUDIOMUSICA_APD_REGISTO`.
2. Pedir a **autorização de transferência internacional** para os prestadores que recebem contactos
   (Meta/WhatsApp, Twilio, fornecedor de SMTP e alojamento, se fora de Angola). Quando existir, definir
   `ESTUDIOMUSICA_APD_AUTORIZACAO=<nº>`: os canais WhatsApp/SMS passam a enviar. Sem isto, só e-mail.
   (`ESTUDIOMUSICA_EXIGIR_AUTORIZACAO_APD=false` desliga a trava — decisão e risco do responsável.)
3. Papéis: cada **estúdio é o responsável pelo tratamento**; a plataforma é **subcontratante**. É preciso um
   contrato/cláusulas de subcontratação entre a plataforma e cada estúdio (ver 3).

### 2.2 Registo das atividades de tratamento (modelo)
| Atividade | Dados | Fundamento | Conservação |
|---|---|---|---|
| Pedidos de orçamento | nome, contactos, mensagem | pedido do titular | 12 meses (`retencao_solicitacoes_meses`) |
| Prestação do serviço | ficha do artista, projetos, sessões, áudio, comentários | contrato | duração da relação |
| Faturação | nome, NIF, valores | obrigação legal | prazo fiscal legal |
| Avisos do serviço | contactos | execução do contrato | duração da relação |
| Marketing | contactos, data de nascimento | consentimento | até retirar |
| Reclamações | nome, contactos, descrição | obrigação legal / interesse legítimo | 24 meses (`retencao_reclamacoes_meses`) |
| Aceite de termos | cliente, data, IP em hash | contrato (prova) | duração da relação + prazo de prescrição **[ADVOGADO]** |

### 2.3 Livro de Reclamações
O Decreto Presidencial n.º 234/16 exige o **Livro de Reclamações físico** nos estabelecimentos. O canal digital
do módulo é um complemento: **o estúdio tem de ter o livro (com selo) no local**.

### 2.4 Direitos sobre masters
O texto-modelo dos Termos é só um ponto de partida. **[ADVOGADO]** deve confirmar: quem é titular do fonograma,
cessão/licença ao estúdio (se houver), uso em portfólio, material de terceiros e prazo de conservação dos ficheiros.

### 2.5 Menores
Artistas menores de 18 anos não contratam sozinhos: recolher autorização do representante legal.
A data de nascimento é guardada (aniversário). **[ADVOGADO]** para o procedimento concreto.

## 3. Dossier para o advogado
1. A plataforma como subcontratante: modelo de contrato e deveres (segurança, confidencialidade, apoio aos direitos).
2. Notificação/autorização na APD: o que, quando, por quem, com que prazo.
3. Fundamento e documentação da transferência internacional (Meta, Twilio, e-mail, alojamento).
4. Texto final dos Termos de Serviço e da Política de Privacidade.
5. Prazos de conservação (fiscal, contratual, reclamações, aceites).
6. Cupões/descontos e publicidade (Lei 15/03).
7. Tratamento de dados de menores e autorização parental.

## 4. Variáveis de ambiente relevantes
`ESTUDIOMUSICA_EXIGIR_AUTORIZACAO_APD`, `ESTUDIOMUSICA_APD_AUTORIZACAO`, `ESTUDIOMUSICA_APD_REGISTO`,
`ESTUDIOMUSICA_FFMPEG_BIN` e, em `config/config.php`, `retencao_solicitacoes_meses`, `retencao_reclamacoes_meses`,
`landing_mostrar_precos`, `checkin_antecedencia_max_horas`.
