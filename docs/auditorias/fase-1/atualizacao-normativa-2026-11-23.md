# Actualização normativa AGT — 2026-11-23

**Repositório:** Saas1 · **Base documental:** PR #58 (Fase 1) · **Estado:** auditoria documental, não homologação.

## Fontes oficiais consultadas

1. **Decreto Presidencial n.º 71/25, de 20 de Março**, Diário da República, I Série, n.º 52: https://www.ucm.minfin.gov.ao/cs/groups/public/documents/document/aw40/otu2/~edisp/minfin4956210.pdf
2. **Decreto Executivo n.º 683/25, de 22 de Agosto**, Diário da República, I Série, n.º 159: https://www.ucm.minfin.gov.ao/cs/groups/public/documents/document/aw40/otuy/~edisp/minfin4952361.pdf
3. **Resenha Legislativa AGT — Janeiro a Agosto de 2025**, que identifica os diplomas 71/25 e 683/25: https://www.ucm.minfin.gov.ao/cs/groups/public/documents/document/aw40/otkw/~edisp/minfin4990676.pdf
4. **Quiosque AGT — Registar Factura**, documentação operacional viva, schema 2.0, consultada em 2026-11-23: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_solicitar_registo.html
5. **Quiosque AGT — Consultar Factura**: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_consultar.html

## 1. Regime jurídico actual identificado

O DP 71/25 estabelece o regime de emissão, rectificação, anulação, conservação e arquivo das facturas e documentos fiscalmente relevantes. O artigo 40.º revoga expressamente o DP 292/18 e o DP 144/23 e derroga os n.os 1 e 5 do artigo 2.º do DP 312/18. As auditorias que ainda usem o DP 292/18 como norma principal devem ser actualizadas.

### Obrigações relevantes para o produto

- **Art. 16.º:** os contribuintes do Regime Geral e Simplificado do IVA estão sujeitos à facturação electrónica; o Regime de Exclusão pode aderir mediante solicitação à AGT.
- **Art. 17.º, n.os 1–3:** transmissão em tempo real e utilização de software validado ou disponibilizado pela AGT.
- **Art. 17.º, n.º 4:** os produtores de software devem assegurar reporte da sua actividade e do valor global das vendas a cada dois meses.
- **Art. 17.º, n.º 5:** perante falha de comunicação superior a 60 dias, o sistema deve bloquear a emissão de novas facturas.
- **Art. 18.º:** prevê emissão em contingência e submissão posterior à AGT, com menção própria e comunicação imediata à Administração.
- **Art. 19.º e art. 22.º:** exigem autenticidade, integridade, legibilidade, controlo de acessos, detecção de alterações não autorizadas, cópias de segurança e documentação técnica.
- **Art. 37.º:** a fase inicial de 12 meses após a entrada em vigor do Decreto Executivo técnico aplica-se aos Grandes Contribuintes e fornecedores do Estado; findo o prazo, a obrigação estende-se aos demais contribuintes sujeitos.

### Calendário transitório e orientação operacional da AGT

O DP 683/25 foi publicado em 22-08-2025 e o seu artigo 6.º determina entrada em vigor 30 dias depois. Pela contagem literal do prazo de 12 meses previsto no artigo 37.º do DP 71/25, a fase transitória terminaria aproximadamente em 21-09-2026.

**Revalidação importante:** o comunicado «Sobre a preparação para a adesão à facturação electrónica», publicado pela AGT em 02-10-2026 no seu portal oficial, apresenta um calendário operacional diferente: facturação electrónica aplicável desde 01-01-2026 aos Grandes Contribuintes e fornecedores do Estado, estendendo-se a partir de **01-01-2027** aos restantes contribuintes dos Regimes Geral e Simplificado do IVA. O Regime de Exclusão pode aderir voluntariamente mediante solicitação.

Fonte primária: portal oficial da AGT, https://agt.minfin.gov.ao/PortalAGT/ (comunicado de 02-10-2026, «Sobre a preparação para a adesão à facturação electrónica»). O portal é uma aplicação web e não devolveu texto legível nesta consulta; foi possível conferir a mensagem oficial numa captura do portal feita em 21-10-2026: https://www.lookuptax.com/docs/img/evidence/ao/2026-10-21-ao-einvoicing-phase-2-2027-correction.webp. A captura é evidência auxiliar, não substitui a fonte primária.

O comunicado operacional da AGT não foi identificado como alteração formal ao DP 71/25 ou ao DP 683/25. Por isso, ficam registados ambos os elementos: a contagem literal dos diplomas e o calendário operacional actualmente divulgado pela AGT. Para decisões fiscais, contratuais ou de activação por segmento, confirmar por escrito com a AGT a articulação entre o artigo 37.º e o comunicado de 02-10-2026. Não usar a data aproximada de Setembro de 2026 como prazo operacional actual sem essa confirmação.

## 2. Especificação técnica e séries

O DP 683/25 define a estrutura de dados e as especificações técnicas da facturação electrónica. O artigo 4.º determina que as séries usadas por contribuintes que emitem em software de facturação electrónica são geradas pela AGT. O artigo 6.º estabelece entrada em vigor 30 dias após a publicação.

A página viva «Registar Factura» documenta schema 2.0, `softwareInfo`, `signatureVersion`, assinatura JWS, máximo de 30 documentos por pedido, tipos documentais e a composição de `lines` e `paymentReceipt`. A implementação deve ser validada contra esta versão operacional e contra o diploma.

## 3. Divergência oficial sobre RC — bloqueio mantido

Existe uma divergência material entre duas fontes oficiais:

- **Anexo I do DP 683/25:** enumera RC em `documentType`; exclui AR, RC e RG de `lines`; e exige `paymentReceipt` para AR, RC e RG.
- **Página viva do Quiosque AGT, consultada em 2026-11-23:** as listas de excepção de `lines` e de obrigatoriedade de `paymentReceipt` mencionam apenas AR e RG. Pela estrutura publicada, RC usa `lines` e não usa `paymentReceipt`.

Não é seguro escolher uma das duas interpretações sem esclarecimento da AGT ou evidência de homologação. Por isso:

- manter o bloqueio actual de emissão/submissão de RC com AGT activa;
- não mudar o builder para `paymentReceipt` em RC apenas com base no anexo;
- não activar AR ou RG sem alocações fiscais imutáveis por linha, assinatura coerente e validação do fluxo FA;
- guardar a versão da especificação, o payload e a resposta da homologação antes de remover bloqueios.

## 4. Achados de implementação no Saas1

### 4.1 Reporte bimestral do produtor

Não foi localizado no código um serviço, estado ou job que comprove o reporte bimestral da actividade e do valor global das vendas do produtor de software previsto no artigo 17.º, n.º 4. A página viva consultada documenta os serviços de solicitar séries, registar facturas e consultar estados, mas não documenta neste conjunto o mecanismo de reporte bimestral. Não inventar endpoint nem payload; solicitar instruções oficiais da AGT.

### 4.2 Bloqueio após 60 dias

`Modules/Faturacao/app/Support/GuardaEmissao.php::garantirComunicacaoAgtEmDia()` usa a idade de submissões pendentes, erro técnico ou intervenção (`created_at`) como aproximação à falha de comunicação. Isto não mede a última comunicação bem-sucedida. Pode bloquear apesar de comunicação recente bem-sucedida e pode não detectar indisponibilidade prolongada se não existirem submissões pendentes. A equivalência com o artigo 17.º, n.º 5, não está demonstrada; requer definição de estado temporal e testes de regressão.

### 4.3 SAF-T e certificação

`SafTExportService` continua a identificar o XML como estrutura provisória. O XSD encontrado no repositório de terceiros não prova ser a versão publicada directamente pela AGT. Não foi encontrada evidência de certificação/homologação do SaaS1. Não declarar conformidade, certificação ou aceitação fiscal.

### 4.4 Tipos documentais

A configuração actual do módulo declara FT, FA, NC, ND, RC e RG. A API viva enumera um conjunto mais amplo de tipos. A ausência de tipos adicionais é uma limitação de âmbito funcional a avaliar por segmento de cliente; não significa, por si só, que todos sejam obrigatórios para todos os contribuintes.

## 5. Próximas acções seguras

1. Obter da AGT esclarecimento escrito sobre a divergência RC no Anexo I versus a API viva.
2. Obter instruções oficiais para o reporte bimestral do produtor de software.
3. Definir e testar o critério exacto de falha de comunicação de 60 dias, sem usar a idade de qualquer submissão como substituto não validado.
4. Obter o XSD SAF-T directamente da AGT e validar um ficheiro completo com todos os documentos aplicáveis.
5. Confirmar certificação do produto e do produtor antes de habilitar produção.
6. Só depois rever os fluxos FA/recebimento/FT/abatimento do Atelier e Estúdio.

**Conclusão:** a base normativa foi actualizada com fontes oficiais; existem divergências e obrigações operacionais ainda não demonstradas. Não foi feita alteração funcional fiscal nesta entrega.