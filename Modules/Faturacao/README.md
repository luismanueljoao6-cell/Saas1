# Faturação & Gestão Financeira

Terceira entrega: clientes, produtos/serviços, faturas com numeração
sequencial sem lacunas e cadeia de assinatura RSA, notas de crédito/débito,
recibos, e um exportador SAF-T (AO). Depende do módulo Core.

## ⚠️ Antes de tudo: o que "estar em conformidade com a AGT" exige

Pesquisei o enquadramento legal atual antes de escrever uma linha de
código, porque a arquitetura certa depende disto. Três factos que mudam o
que esta entrega pode e não pode garantir sozinha:

1. **Desde 1 de janeiro de 2026, a faturação eletrónica é obrigatória em
   Angola para grandes contribuintes**, alargando-se a todas as empresas
   em 2027. O enquadramento atual vem do **Decreto Presidencial n.º 71/25**
   (Regime Jurídico das Facturas) e do **Decreto Executivo n.º 317/20**
   (estrutura do ficheiro SAF-T de contabilidade).
2. **É preciso usar software certificado pela AGT.** Isto é uma resposta
   direta e confirmada, não uma dedução minha. Construir bem a cadeia de
   hash, a numeração sem lacunas e a imutabilidade — que é o que esta
   entrega faz — é necessário, mas **não é, por si só, suficiente** para
   emitir faturas legalmente válidas em nome de terceiros. Isso exige um
   processo de certificação da AGT (ou usar/embeber um motor já
   certificado).
3. A assinatura de cada documento é feita com a chave **privada do
   produtor do software** (não da empresa cliente), e a chave pública
   correspondente só é válida depois de comunicada à AGT através de uma
   **declaração modelo 24**. Gerar um par de chaves localmente (o que este
   módulo faz) é o primeiro passo técnico — não substitui essa
   comunicação.

**O que isto significa na prática para ti:** usa este módulo para
desenvolvimento, para validar o fluxo interno da tua aplicação, e como
ponto de partida técnico sólido — mas antes de qualquer empresa real
emitir uma fatura real através dele, confirma com a AGT (ou um contabilista
certificado em Angola) o caminho de certificação, porque isso é um
processo administrativo, não uma linha de código.

## Sobre o mecanismo de hash — o que encontrei

A AGT descreve o mecanismo num ofício (Ofício Circulado 50001/2013, do
Gabinete do Subdiretor-Geral da Inspeção Tributária), remetendo para as
regras da **Portaria n.º 363/2010** — a mesma referência legal usada em
Portugal para o SAF-T-PT, o que sugere fortemente que o mecanismo da AGT
foi desenhado sobre esse modelo. As regras que encontrei, e que implementei
tal como descritas:

- Cada documento é assinado com **RSA**, usando a chave privada do
  produtor do software.
- A assinatura fica guardada em claro na base de dados (não encriptada),
  ligada ao documento.
- Regista-se também a **versão da chave** usada (inteiro sequencial) — a
  troca de chaves só pode ser feita pelo produtor do software, e implica
  comunicar a nova chave pública à AGT.
- **A assinatura de cada documento depende do hash do documento anterior
  da MESMA série/tipo.** No primeiro documento de cada série, esse campo
  fica vazio.

Isto está implementado no `AssinaturaFiscalService` e testado no
`CadeiaFiscalTest`. O que **não** confirmei com a mesma certeza é o
formato exato da string assinada campo a campo — reconstruí-a a partir do
que é conhecido publicamente sobre a família SAF-T lusófona
(`Fatura::construirStringParaAssinar()` tem o detalhe e o aviso). Confirma
isto contra a documentação técnica atual da AGT antes de produção.

## Estrutura entregue

```
Modules/Faturacao/
├── module.json, composer.json
├── config/config.php                    # chave RSA, algoritmo, taxa de IVA geral
├── database/migrations/                 # series, clientes, produtos, faturas(+linhas),
│                                         # notas_credito_debito(+linhas), recibos
├── app/
│   ├── Contracts/DocumentoFiscalInterface.php   # unifica Fatura/NotaCreditoDebito/Recibo
│   ├── Models/{Serie,Cliente,Produto,Fatura,FaturaLinha,NotaCreditoDebito,NotaCreditoDebitoLinha,Recibo}.php
│   ├── Services/
│   │   ├── NumeracaoService.php         # numeração sequencial sem lacunas, com lock
│   │   ├── AssinaturaFiscalService.php  # cadeia de hash RSA
│   │   ├── FaturaService.php            # criar rascunho + emitir
│   │   ├── NotaCreditoDebitoService.php # idem, incl. anular uma fatura
│   │   └── SafTExportService.php        # gera o XML SAF-T (AO)
│   ├── Traits/Imutavel.php              # bloqueia alteração/exclusão pós-emissão
│   ├── Http/Controllers/{Cliente,Produto,Fatura,SafTExport}Controller.php
│   ├── Jobs/GerarSafTJob.php
│   └── Providers/{FaturacaoServiceProvider,RouteServiceProvider}.php
├── routes/web.php
├── resources/views/{clientes,produtos,faturas,saft}/*.blade.php
└── tests/Feature/CadeiaFiscalTest.php
```

## Decisões de arquitetura mais importantes

**1. `DocumentoFiscalInterface` unifica Fatura, Nota de Crédito/Débito e
Recibo.** A AGT aplica a mesma disciplina (numeração + hash encadeado) a
vários tipos de documento, não só à fatura — por isso `NumeracaoService` e
`AssinaturaFiscalService` não sabem o que é uma "Fatura"; sabem falar com
qualquer coisa que implemente o contrato. Acrescentar um novo tipo de
documento fiscal no futuro é implementar a interface, não reescrever a
cadeia de assinatura.

**2. Emissão em duas fases: `prepararParaEmissao()` + `confirmarEmissao()`.**
A string a assinar precisa do número e da data já atribuídos, mas o hash
só existe depois de assinar — por isso o model primeiro recebe os dados
"provisórios" (fase 1), o `AssinaturaFiscalService` constrói e assina a
string, e só na fase 2 o documento passa a `emitida`. Antes disso, a
`Imutavel` trait não bloqueia nada — o bloqueio olha sempre para o estado
ORIGINAL do registo, nunca para o novo.

**3. Numeração sem lacunas sob concorrência real.** `NumeracaoService`
usa `lockForUpdate()` dentro de uma transação: duas emissões em
simultâneo para a mesma empresa/série nunca saem com o mesmo número nem
saltam um. A criação da própria Serie (na primeira fatura do ano) trata a
race condition da unique constraint explicitamente — ver comentários.

**4. Imutabilidade é só a primeira camada de defesa.** A trait `Imutavel`
protege ao nível do Eloquent (mesmo um super admin da plataforma não
consegue alterar uma fatura emitida por este caminho). Para produção,
considera reforçar com uma segunda camada ao nível da base de dados —
por exemplo, revogar o privilégio `UPDATE`/`DELETE` do utilizador de
aplicação nas tabelas `faturas`, `notas_credito_debito` e `recibos` para
linhas com `estado` final, ou um trigger. A trait não substitui isso.

**5. Correção = novo documento, nunca edição.** `NotaCreditoDebitoService::criarParaAnularFatura()`
é o caminho para corrigir um erro numa fatura já emitida — replica as
linhas da fatura original numa nota de crédito nova, com a sua própria
numeração e cadeia de hash (série `NC`, nunca misturada com `FT`).

**6. `Empresa::podeEmitirFaturas()` é verificado no controller, não só no
middleware.** O Core deixou isto pronto: `subscricao.ativa` bloqueia
acesso total fora do grace period, mas `FaturaController` verifica
adicionalmente `podeEmitirFaturas()` antes de criar ou emitir — durante o
grace period, consegues consultar faturas antigas mas não criar novas
(ver `garantirQuePodeEmitirFaturas()`).

## O que fica para depois (por desenho)

- **Certificação AGT do software** — é um processo administrativo, não
  técnico; ver aviso no topo.
- **Recibos e notas de crédito/débito não têm UI própria** — os models e
  services existem e estão testados (`NotaCreditoDebitoService`), falta o
  controller/views equivalentes aos de Fatura.
- **Validação do SAF-T contra o XSD oficial** — o `SafTExportService`
  gera uma estrutura plausível, não validada campo a campo.
- **Faturas de outros módulos** (ex.: a subscrição SaaS da própria
  plataforma) não passam por aqui — este módulo fatura AS EMPRESAS
  clientes da plataforma aos SEUS PRÓPRIOS clientes, não a relação entre
  a plataforma e as empresas (essa é o Módulo de Subscrições).

## Instalação

1. **Copiar o módulo** (a seguir ao Core) e `composer dump-autoload`.

2. **Gerar o par de chaves RSA** (nunca committer a privada):
   ```bash
   mkdir -p storage/app/faturacao
   openssl genrsa -out storage/app/faturacao/chave-privada.pem 2048
   openssl rsa -in storage/app/faturacao/chave-privada.pem -pubout -out storage/app/faturacao/chave-publica.pem
   echo "storage/app/faturacao/" >> .gitignore
   ```

3. **Variáveis de ambiente**:
   ```
   FATURACAO_CHAVE_PRIVADA_PATH=storage/app/faturacao/chave-privada.pem
   FATURACAO_CHAVE_VERSAO=1
   FATURACAO_TAXA_IVA_GERAL=14.0
   FATURACAO_SAFT_NUMERO_CERTIFICADO=0
   ```

4. **Migrar**
   ```bash
   php artisan migrate
   ```
   Não há seeder — as séries de numeração criam-se sozinhas na primeira
   emissão de cada ano (ver `NumeracaoService::obterOuCriarSerie`).

5. **Correr os testes** (geram e descartam a sua própria chave RSA de teste,
   não usam a chave de produção):
   ```bash
   php artisan test --filter=CadeiaFiscalTest
   ```

## Rotas principais

| Rota | Método | Descrição |
|---|---|---|
| `/faturacao/clientes` | GET/POST | Listar/criar clientes |
| `/faturacao/produtos` | GET/POST | Listar/criar produtos |
| `/faturacao/faturas` | GET | Listar faturas |
| `/faturacao/faturas/criar` | GET | Formulário de nova fatura |
| `/faturacao/faturas` | POST | Guardar rascunho |
| `/faturacao/faturas/{fatura}` | GET | Ver fatura (rascunho ou emitida) |
| `/faturacao/faturas/{fatura}/emitir` | POST | Emitir (numeração + hash, irreversível) |
| `/faturacao/saft` | GET/POST | Pedir exportação SAF-T (AO) |

## Sugestão para a próxima entrega

Core, Subscrições e Faturação cobrem o essencial "genérico" do sistema.
Atelier de Costura e Estúdio de Música ficam agora como módulos de negócio
verticais — cada um só precisa de `BelongsToTenant` nos seus models e,
onde vender bens/serviços a clientes gerar necessidade de fatura, podem
chamar `FaturaService` diretamente (ex.: fechar uma encomenda do Atelier e
criar logo o rascunho de fatura correspondente).
