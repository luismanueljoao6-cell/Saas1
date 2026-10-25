# Chaves de assinatura — gestão, verificação e rotação

Cada documento fiscal é assinado (JWS RS256) com uma chave RSA privada. Este
documento diz onde ficam as chaves, como se verifica que estão certas e como se
faz a rotação. Os passos que dependem da AGT (pedir/comunicar/revogar uma chave)
estão marcados **[confirmar com a AGT]**: segue sempre a página "Gestão de
Certificados e Chaves" da documentação oficial da Facturação Electrónica.

## Regras

| Chave | Onde | Quem a usa |
|---|---|---|
| **Da empresa** (emissor) | `{FATURACAO_CHAVES_DIR}/{NIF}.pem` | assina os documentos dessa empresa |
| **Do software** (produtor) | `FATURACAO_AGT_CHAVE_SOFTWARE` | assina o `softwareInfo` dos pedidos à AGT |
| **Genérica** (`FATURACAO_CHAVE_PRIVADA_PATH`) | só desenvolvimento | só é aceite com `APP_ENV` local/testing **e** a AGT desligada |

- Uma chave por empresa. Duas empresas nunca partilham a chave, e a chave do software
  é diferente das dos emissores.
- RSA com pelo menos 2048 bits (`faturacao.chave_minima_bits`). Chaves mais fracas são recusadas.
- Fora de desenvolvimento, se não existir `{NIF}.pem`, a emissão **falha** — nunca recorre à
  chave genérica. Assinar sem empresa (por exemplo o `softwareInfo`) também falha fora de
  desenvolvimento se não for pela chave do software.
- Ficheiros `0600`, pertencentes ao utilizador que corre o PHP; nunca no Git
  (`storage/app/faturacao/` está no `.gitignore`); cópias de segurança **cifradas e separadas**
  das cópias da base de dados.

## Verificar

```bash
php artisan faturacao:verificar-chaves            # todas as empresas
php artisan faturacao:verificar-chaves --empresa=3
```

Falha (código de saída 1) se: faltar a chave de uma empresa (com a AGT ativa ou em
produção), a chave não for RSA ≥ 2048 bits, duas empresas partilharem a chave, a chave
genérica for igual à de uma empresa, a chave do software faltar ou for igual à de um
emissor. Avisa se as permissões forem abertas ou se a chave genérica ainda estiver no
servidor. Corre-o antes de cada deploy, depois de restaurar uma cópia de segurança e
depois de qualquer rotação.

A auditoria da cadeia usa a chave pública de cada empresa (derivada da respetiva chave
privada, neste servidor); se essa chave não existir, recorre ao ficheiro
`faturacao.chave_publica_path` (`FATURACAO_CHAVE_PUBLICA_PATH`):

```bash
php artisan faturacao:verificar-cadeia --agt
php artisan faturacao:verificar-cadeia --empresa=3 --chave-publica=/cofre/5001636863.v1.pub.pem
```

## Rotação (chave de uma empresa)

1. **[confirmar com a AGT]** Obter a chave nova e comunicar a chave pública, se o processo o exigir.
2. **Arquivar a chave pública antiga** (a pública não é segredo) em
   `NIF.v{N}.pub.pem`, num sítio seguro. Sem ela não se verificam os documentos já assinados:
   cada documento guarda o `chave_versao` com que foi assinado.
3. Aumentar `FATURACAO_CHAVE_VERSAO` (inteiro sequencial).
4. Gravar a chave nova em `{NIF}.pem` com `chmod 600`.
5. `php artisan faturacao:verificar-chaves --empresa=ID` tem de passar.
6. Em **homologação**: emitir e enviar um documento de teste e confirmar o estado `válido`.
7. Documentos antigos verificam-se com a chave pública arquivada
   (`faturacao:verificar-cadeia --empresa=ID --chave-publica=...`).

## Perda ou fuga de uma chave

- **Fuga:** parar a emissão dessa empresa, **[confirmar com a AGT]** a revogação e a chave de
  substituição, e rodar como acima. Os documentos assinados com a chave exposta continuam
  na cadeia: não se alteram nem se reassinam.
- **Perda:** sem a privada não se emite; com a pública arquivada continuam verificáveis os
  documentos antigos. Pede-se nova chave **[confirmar com a AGT]**.
