# Gestão das chaves de assinatura fiscal

Este documento descreve o comportamento técnico actual do módulo. **Não confirma, por si só, que o âmbito da chave esteja homologado pela AGT**; esse ponto continua aberto em [homologacao-agt.md](homologacao-agt.md), H10.

## Ambientes locais e testes

A chave genérica definida por `FATURACAO_CHAVE_PRIVADA_PATH` só pode ser usada quando:
- `APP_ENV=local` ou `APP_ENV=testing`;
- `FATURACAO_AGT_ATIVO=false`.

Nunca uses a chave genérica para uma instalação de produção.

## Instalação técnica actual

A implementação de produção resolve a chave privada por NIF:

```text
FATURACAO_CHAVES_DIR/
└── {NIF}.pem
```

Configuração:

```dotenv
FATURACAO_CHAVES_DIR=/caminho/privado/faturacao/chaves
FATURACAO_CHAVE_VERSAO=1
```

Requisitos operacionais:
- RSA com pelo menos 2048 bits;
- directório fora do repositório e sem acesso público;
- permissões mínimas necessárias para o utilizador do processo;
- não colocar chaves privadas em commits, issues, logs, tickets ou mensagens;
- manter cópias de segurança protegidas e testar a recuperação;
- nunca substituir uma chave privada sem preservar a versão anterior enquanto existirem documentos assinados com ela.

## Arquivo de chaves públicas

Na emissão, o serviço obtém a chave pública correspondente à chave privada usada e guarda:
- `empresa_id`;
- versão da chave;
- PEM público;
- fingerprint SHA-256.

A tabela `faturacao_chaves_publicas` tem uma restrição única por empresa e versão. Uma versão não pode ser reutilizada com outra chave. A chave privada **não** é guardada nessa tabela.

Antes de submeter à AGT ou publicar SAF-T, a aplicação:
1. resolve a chave pública pela empresa e pela versão gravada no documento;
2. verifica a assinatura JWS RS256;
3. compara o payload assinado com os dados fiscais persistidos.

Se faltar a chave histórica, o JWS estiver inválido ou os dados divergirem, a operação é bloqueada.

## Rotação

1. Recupera e preserva a chave privada anterior e confirma que a chave pública da versão anterior está arquivada.
2. Instala a nova chave privada no caminho da empresa.
3. Incrementa `FATURACAO_CHAVE_VERSAO` antes de emitir novos documentos.
4. Emite um documento de teste em ambiente controlado e confirma o registo da nova chave pública.
5. Verifica documentos de ambas as versões e confirma que os antigos continuam a usar a chave antiga.

Não reutilizes uma versão. Se a aplicação encontrar a mesma versão associada a outro fingerprint, a emissão é interrompida.

### Documentos anteriores ao arquivo

Documentos emitidos antes da migration `faturacao_chaves_publicas` podem não ter uma chave pública histórica registada. Não é seguro associar-lhes automaticamente a chave actualmente instalada. Recupera a chave original e confirma a versão antes de qualquer procedimento de arquivo. Até lá, esses documentos ficam bloqueados para submissão/exportação fiscal.

## Verificação operacional

Executa a auditoria antes de activar a comunicação AGT e depois de qualquer rotação:

```bash
php artisan faturacao:verificar-chaves
php artisan faturacao:verificar-chaves --empresa=ID
```

O comando verifica os ficheiros privados, partilha indevida de chaves, versões históricas em falta ou com fingerprint inválido e divergência entre a chave instalada e a chave arquivada na versão actual.

## Limite de homologação

A implementação actual resolve chaves por empresa/NIF, mas a documentação técnica vigente da AGT tem de confirmar se a chave de assinatura deve pertencer ao produtor do software ou à empresa emitente, bem como o processo de comunicação e rotação. Não activar produção nem declarar conformidade com base apenas nos testes internos.
