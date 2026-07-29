# 🧭 ContextEngine do zero: guia completo para iniciantes

Este guia ensina a construir um sistema que lê uma política de reembolso, prepara seu conteúdo para pesquisa e responde perguntas usando os trechos encontrados. Tudo foi conferido contra o código atual.

> **Estado atual:** biblioteca funcional em estágio inicial de produção. Ela fornece o motor de ingestão e RAG; sua aplicação fornece banco, credenciais, configuração e interface.

## 1. O que é o ContextEngine

Imagine uma empresa com manuais, regulamentos e procedimentos. Um funcionário pergunta “Em quanto tempo devo solicitar um reembolso?” e quer uma resposta baseada nesses arquivos.

O ContextEngine fornece peças PHP para ler documentos, dividi-los, prepará-los para pesquisa por significado, armazená-los, encontrar trechos relacionados e pedir a uma inteligência artificial que responda com base nas fontes.

Ele é uma **biblioteca PHP**, não uma aplicação pronta. Não possui tela, API HTTP, painel ou autenticação.

```text
O ContextEngine é o motor. Sua aplicação é o carro completo.
```

## 2. O problema resolvido

Se uma empresa possui 500 documentos, enviar todos à IA em cada pergunta seria lento, caro e poderia ultrapassar o limite do modelo. O ContextEngine prepara um índice inteligente:

```text
Documentos → divisão em trechos → transformação em números → armazenamento
Pergunta → transformação em números → procura por significado
         → envio dos melhores trechos à IA → resposta
```

Os nomes técnicos são **ingestão**, **chunking**, **embeddings**, **busca vetorial**, **retrieval** e **RAG**.

## 3. Glossário para iniciantes

### RAG

Técnica em que a IA recebe informações encontradas nos documentos antes de responder. É como permitir que alguém consulte um livro antes de responder. `RagPipeline` coordena essa etapa.

### Documento e loader

Um **documento** é a unidade original, representada por `Document`. Um **loader** lê ou fornece documentos: é a porta de entrada. O pacote inclui `TextFileLoader`.

### Ingestão

Preparação completa para pesquisas futuras: leitura, divisão, geração de embeddings e armazenamento. Não significa apenas upload. `IngestionPipeline` coordena o processo.

### Chunk e splitter

Um **chunk** é uma parte pequena do documento. O **splitter** é o componente que faz a divisão.

```text
Política de reembolso
├── Chunk 1: quem pode solicitar
├── Chunk 2: prazo de 30 dias
└── Chunk 3: documentos necessários
```

`RecursiveTextSplitter` tenta parágrafos, linhas, sentenças e palavras antes de cortar por caracteres.

### Vetor, embedding e dimensão

Um **vetor** é uma lista de números, como `[0.12, -0.44, 0.91]`. Um **embedding** é um vetor criado para representar o significado de um texto. Pense em uma coordenada num mapa: textos parecidos ficam próximos.

Embedding não é resumo, criptografia nem substituto do texto. A **dimensão** é a quantidade de números; o OpenAI provider usa 1.536 por padrão.

### Modelo e espaço de embedding

O **modelo de embedding** transforma texto em números. O **espaço vetorial** é o “mapa” usado por esse modelo. Não se comparam coordenadas de mapas diferentes.

`EmbeddingSpace` registra provider, model, dimensions, revision e parameters. Seu `fingerprint` é uma etiqueta determinística dessa configuração. Ele aparece na geração, cache, banco e busca para impedir mistura incompatível.

### Provider, OpenAI e Ollama

Um **provider** adapta a biblioteca a um serviço. OpenAI normalmente exige internet e API key. Ollama permite executar modelos localmente, conforme a máquina e o modelo instalado.

O pacote oferece embeddings OpenAI/Ollama e respostas completas OpenAI. Não oferece atualmente um `OllamaLanguageModel`.

### PostgreSQL, pgvector e busca vetorial

PostgreSQL é o banco usado. **pgvector** é sua extensão para guardar e comparar vetores. A **busca vetorial** procura significado, não apenas palavras idênticas:

```text
“Como pedir meu dinheiro de volta?”
“Procedimento para solicitação de reembolso”
```

### Retrieval, contexto, prompt e LLM

**Retrieval** recupera os chunks relevantes. Esses chunks formam o **contexto**. O **prompt** organiza instrução, pergunta e contexto. O **LLM** é o modelo que redige a resposta. No código: `Retriever`, `ContextPromptBuilder` e `LanguageModel`.

### Pipeline, tenant e collection

Uma **pipeline** é uma linha de montagem de etapas. Um **tenant** é uma organização isolada, como `empresa-a`; documentos dela não devem aparecer para `empresa-b`. Uma **collection** é uma pasta lógica dentro do tenant, como `financeiro`.

### Cache, Redis, Fiber e Future

**Cache** guarda cálculos para reutilização. **PSR-16** é a interface aceita, e Redis pode ser seu armazenamento compartilhado. É opcional.

Fiber ajuda internamente a manter chamadas concorrentes. Future representa um resultado futuro dentro desse mecanismo. O usuário não manipula nenhum deles; `Future` não aparece na API pública.

## 4. Antes de começar

### Obrigatório

- PHP `^8.4`;
- Composer;
- extensões `pdo`, `pdo_pgsql` e `sockets`;
- PostgreSQL com pgvector;
- banco e schema criados;
- acesso aos quatro repositórios Git Omegaalfa.

```bash
php -v
composer --version
php -m | grep -E 'PDO|pdo_pgsql|sockets'
```

### Escolha para embeddings

- OpenAI: internet e chave; este guia usa `text-embedding-3-small`, 1.536 dimensões.
- Ollama: serviço ativo, modelo instalado e dimensão conhecida.

Para a resposta final, a implementação incluída é `OpenAILanguageModel`. Redis, Docker, `omegaalfa/collection` e `omegaalfa/lazy-object` são opcionais.

## 5. O projeto de exemplo

Carregaremos `documents/politica-reembolso.txt` e perguntaremos “Em quanto tempo devo solicitar um reembolso?”.

```text
context-engine-example/
├── documents/politica-reembolso.txt
├── composer.json
├── schema.sql
└── example.php
```

## 6. Criando o projeto

```bash
mkdir context-engine-example
cd context-engine-example
mkdir documents
```

Execute no local em que guarda projetos. O resultado é uma aplicação consumidora separada da biblioteca.

## 7. Instalando a biblioteca

Em 29/07/2026, a busca não confirmou `omegaalfa/context-engine` no Packagist. O GitHub é público, mas o pacote e três dependências usam `dev-main`. Use esta instalação VCS, sem inventar uma versão estável:

```json
{
  "name": "exemplo/context-engine-example",
  "type": "project",
  "require": {
    "php": "^8.4",
    "omegaalfa/context-engine": "dev-main"
  },
  "repositories": [
    {"type": "vcs", "url": "https://github.com/omegaalfa/ContextEngine"},
    {"type": "vcs", "url": "https://github.com/omegaalfa/query-builder"},
    {"type": "vcs", "url": "https://github.com/omegaalfa/HttpClient"},
    {"type": "vcs", "url": "https://github.com/omegaalfa/FiberEventLoop"}
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

`require` declara o pacote. `repositories` informa onde encontrá-lo. `dev-main` é a branch em desenvolvimento; `minimum-stability` permite essa versão. Instale:

```bash
composer install
```

Espere `vendor/`, `composer.lock` e `vendor/autoload.php`. Erros de resolução geralmente indicam repositório irmão ausente.

## 8. Preparando PostgreSQL

```bash
createdb context_engine_example
psql -d context_engine_example -c 'CREATE EXTENSION IF NOT EXISTS vector;'
```

Salve em `schema.sql` e execute com `psql -d context_engine_example -f schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS context_chunks (
    chunk_id text NOT NULL,
    document_id text NOT NULL,
    tenant_id text NOT NULL,
    collection text NOT NULL,
    status text NOT NULL,
    content text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    metadata jsonb NOT NULL DEFAULT '{}',
    embedding vector(1536) NOT NULL,
    embedding_provider text NOT NULL,
    embedding_model text NOT NULL,
    embedding_dimensions integer NOT NULL CHECK (embedding_dimensions = 1536),
    embedding_revision text NOT NULL CHECK (embedding_revision <> ''),
    embedding_space_fingerprint text NOT NULL CHECK (embedding_space_fingerprint <> ''),
    CONSTRAINT context_chunks_identity PRIMARY KEY (
        tenant_id, collection, chunk_id, embedding_space_fingerprint
    )
);

CREATE INDEX IF NOT EXISTS context_chunks_scope_idx ON context_chunks
    (tenant_id, collection, status, embedding_space_fingerprint);
```

A chave composta impede duplicação da mesma versão no mesmo escopo. Texto e metadata permanecem ao lado do embedding.

> A fixture oficial usa `vector(3)` apenas nos testes. O schema acima foi adaptado ao padrão OpenAI de 1.536. Para outro modelo, ajuste ambos os números antes de criar a tabela.

## 9. Opção com Docker

O compose do repositório inicia pgvector em `localhost:54329` e Redis em `localhost:63799`:

```bash
cp .env.example .env
docker compose --env-file .env --profile integration up -d --wait
docker compose --env-file .env --profile integration ps
docker compose --env-file .env --profile integration down
```

Docker não é obrigatório. Esse compose monta a fixture `vector(3)` e é determinístico para testes, não para o exemplo OpenAI. Uma aplicação real deve manter seu compose/schema de 1.536 dimensões. O SQL de inicialização só roda quando o volume é criado.

## 10. OpenAI ou Ollama

### A — OpenAI

```bash
export OPENAI_API_KEY='sua-chave-aqui'
```

Não grave a chave em PHP ou Git. O provider usa `text-embedding-3-small`, 1.536 dimensões e `https://api.openai.com/v1` por padrão. O LLM padrão é `gpt-4.1-mini`; disponibilidade e preço devem ser conferidos na conta OpenAI.

### B — Ollama

O ContextEngine espera `http://127.0.0.1:11434` e chama `/api/embed`. Verificação geral:

```bash
curl http://127.0.0.1:11434/api/tags
```

```php
$embeddingProvider = new OllamaEmbeddingProvider(
    model: 'NOME_CONFIRMADO_DO_MODELO',
    dimensions: DIMENSAO_CONFIRMADA,
);
```

Nome e dimensão dependem do modelo instalado e não são determinados pelo pacote. Adapte o schema. Ollama cobre embeddings; para a resposta final, implemente `LanguageModel` ou use OpenAI.

## 11. Configurando as dependências

Cada peça abaixo participa de uma etapa do fluxo. O exemplo completo reúne todas na seção 26.

### 11.1 Cliente HTTP e event loop

O cliente envia JSON aos providers; o event loop permite concorrência controlada. Ambos poderiam usar os valores padrão dos construtores, mas aparecem explicitamente para facilitar o entendimento.

```php
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

$http = new AsyncHttpClient();
$eventLoop = new FiberEventLoop();
```

Resultado: dois objetos de infraestrutura. Sua aplicação nunca recebe um `Future`.

### 11.2 Provider de embeddings e espaço

```php
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;

$embeddingProvider = new OpenAIEmbeddingProvider(
    apiKey: $openAiKey,
    model: 'text-embedding-3-small',
    dimensions: 1536,
    client: $http,
);
$space = $embeddingProvider->space();
```

O provider transforma texto em embedding. `space()` retorna a identidade efetivamente usada; não construa uma identidade diferente em paralelo.

### 11.3 Conexão e QueryBuilder

```php
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

$settings = new DatabaseSettings(
    driver: 'pgsql',
    host: $databaseHost,
    database: $databaseName,
    port: $databasePort,
    username: $databaseUser,
    password: $databasePassword,
);
$connection = new PDOConnection($settings);
$queryBuilder = new QueryBuilder($connection);
```

`DatabaseSettings` descreve o acesso; `PDOConnection` abre a conexão; `QueryBuilder` prepara SQL.

### 11.4 Store, splitter e executor

```php
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;

$vectorStore = new PgVectorStore($queryBuilder);
$splitter = new RecursiveTextSplitter(chunkSize: 500, overlap: 75);
$executor = new FiberBatchEmbeddingExecutor(
    loop: $eventLoop,
    concurrency: 4,
);
```

O store persiste; o splitter divide; o executor mantém no máximo quatro lotes HTTP em andamento e entrega resultados para persistência serial.

### 11.5 Pipeline de ingestão

```php
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;

$ingestion = new IngestionPipeline(
    splitter: $splitter,
    embeddings: $embeddingProvider,
    store: $vectorStore,
    batchSize: 16,
    executor: $executor,
);
```

### 11.6 Retriever e política

```php
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$retriever = new Retriever(
    embeddings: $embeddingProvider,
    store: $vectorStore,
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: 'default',
);
```

Ele gera embedding da pergunta e recupera até cinco chunks compatíveis da collection.

### 11.7 Prompt, modelo e RAG

```php
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Rag\RagPipeline;

$promptBuilder = new ContextPromptBuilder();
$languageModel = new OpenAILanguageModel(
    apiKey: $openAiKey,
    model: 'gpt-4.1-mini',
    client: $http,
);
$rag = new RagPipeline($retriever, $promptBuilder, $languageModel);
```

O modelo retorna resposta completa e não implementa streaming incremental.

## 12. `EmbeddingSpace` com calma

```php
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

$space = new EmbeddingSpace(
    provider: 'openai',
    model: 'text-embedding-3-small',
    dimensions: 1536,
    revision: '1',
    parameters: ['dimensions' => 1536],
);
echo $space->fingerprint();
```

Imagine que cada modelo usa um mapa. Provider identifica quem fez o mapa; model identifica o modelo; dimensions, o tamanho das coordenadas; revision, uma mudança controlada; parameters, opções semanticamente relevantes; fingerprint, a etiqueta calculada de tudo isso.

Não inclua chave, endpoint ou timeout em `parameters`: não alteram o significado do vetor. Se `dimensions` divergir do modelo ou de `vector(n)`, a criação do `Embedding` ou o banco falhará.

## 13. Documento de exemplo

Salve em `documents/politica-reembolso.txt`:

```text
Política de reembolso

O colaborador pode solicitar reembolso de despesas profissionais previamente autorizadas. A solicitação deve ser enviada em até 30 dias corridos após a data da despesa.

O pedido deve incluir a nota fiscal legível, a justificativa da despesa e a identificação do centro de custo. Solicitações sem comprovante podem ser devolvidas para correção.

Depois da aprovação do gestor, o pagamento é processado na folha seguinte. Dúvidas devem ser encaminhadas ao setor financeiro.
```

As linhas em branco fazem o loader produzir um `Document` por bloco não vazio.

## 14. Carregando o documento

```php
use Omegaalfa\ContextEngine\Loader\TextFileLoader;

$loader = new TextFileLoader(
    path: __DIR__ . '/documents/politica-reembolso.txt',
    tenantId: 'empresa-a',
);
```

O loader aceita apenas caminho e tenant. Ele cria ID como SHA-256 de caminho + índice, metadata `source`, collection `default` e status `active`.

> ID, collection, status e metadata personalizados exigem um `DocumentLoader` próprio que produza objetos `Document`. Não existem esses parâmetros no `TextFileLoader` atual.

## 15. Dividindo o documento

`chunkSize: 500` limita aproximadamente o trecho a 500 caracteres. `overlap: 75` repete parte entre trechos para evitar cortar uma ideia exatamente na divisão.

Chunks muito pequenos perdem contexto e aumentam chamadas. Chunks muito grandes misturam assuntos e ocupam mais prompt. O splitter usa generator, produzindo chunks conforme o consumo.

## 16. Fazendo a ingestão

```php
$report = $ingestion->ingest($loader);

printf(
    "Completa: %s | chunks salvos: %d | lotes salvos: %d\n",
    $report->complete ? 'sim' : 'não',
    $report->chunksPersisted,
    $report->batchesPersisted,
);
```

`ingest()` lê, divide, agrupa, envia ao provider, valida, persiste e devolve `IngestionReport`. Campos reais:

- `batchesPlanned`, `batchesStarted`, `batchesCompleted`;
- `batchesPersisted`, `batchesDiscarded`;
- `chunksProduced`, `chunksSent`, `chunksPersisted`;
- `firstFailure`, `affectedBatchSequences`, `complete`.

Uma saída possível é `Completa: sim | chunks salvos: 3 | lotes salvos: 3`; números dependem do conteúdo.

## 17. O que foi salvo

```sql
SELECT tenant_id, collection, chunk_id, document_id, position, status,
       left(content, 100) AS preview,
       embedding_provider, embedding_model, embedding_dimensions,
       embedding_revision, embedding_space_fingerprint
FROM context_chunks
ORDER BY tenant_id, collection, document_id, position;
```

`content` mantém o texto; `embedding`, o vetor; `metadata`, JSON; as colunas `embedding_*`, a identidade do espaço.

## 18. Fazendo uma pergunta

```php
$answer = $rag->ask(
    'Em quanto tempo devo solicitar um reembolso?',
    'empresa-a',
);
echo $answer->content . PHP_EOL;
```

A pergunta vira embedding; o banco aplica tenant, collection, status e espaço; os chunks viram contexto; a OpenAI redige a resposta. Uma saída ilustrativa é “A solicitação deve ser enviada em até 30 dias corridos”. A redação pode variar.

## 19. Entendendo as fontes

```php
foreach ($answer->sources as $source) {
    printf(
        "[%s] distância=%.4f documento=%s\n%s\n",
        $source->chunk->id,
        $source->distance,
        $source->chunk->documentId,
        $source->chunk->content,
    );
}
```

Cada fonte é `VectorSearchResult`, contendo `chunk` e `distance`. O chunk expõe ID, documento, tenant, conteúdo, posição, metadata, collection e status.

## 20. Tenant e collection

```text
empresa-a / recursos-humanos
empresa-b / recursos-humanos
```

Collections podem ter o mesmo nome, mas tenants diferentes permanecem isolados. `Question` exige tenant e `PgVectorStore` o filtra no SQL. Dentro de um tenant, `recursos-humanos`, `financeiro` e `suporte` separam assuntos.

Como `TextFileLoader` usa `default`, collections personalizadas exigem loader próprio com `new Document(..., collection: 'financeiro')`.

## 21. Utilizando cache

No primeiro teste, ignore cache. Primeiro confirme banco, ingestão e resposta.

```php
use Omegaalfa\ContextEngine\Cache\CachedEmbeddingProvider;

$embeddingProvider = new CachedEmbeddingProvider(
    provider: $openAiEmbeddingProvider,
    cache: $psr16Cache,
    ttl: 3600,
);
```

O cache precisa implementar `Psr\SimpleCache\CacheInterface`. A chave de embedding considera tenant, provider, modelo, dimensão, fingerprint e hash exato do texto; duplicatas no lote não repetem chamada.

```php
use Omegaalfa\ContextEngine\Cache\CachedLanguageModel;

$languageModel = new CachedLanguageModel(
    model: $openAiLanguageModel,
    cache: $psr16Cache,
    tenantId: 'empresa-a',
    promptVersion: $promptBuilder->version,
    enabled: true,
    ttl: 600,
);
```

Cache de resposta é desativado por padrão porque LLMs podem variar. A chave inclui tenant, identidade do modelo, mensagens/contexto e versão do prompt. Falhas e streaming não são cacheados.

O pacote não inclui adaptador Redis PSR-16. Compose e teste confirmam apenas Redis autenticado/persistente; a aplicação escolhe uma implementação PSR-16. Por isso não é inventada aqui uma classe Redis específica.

## 22. Atualizando um documento

O store usa **upsert**: insere se não existe e atualiza se já existe. A identidade é:

```text
tenant + collection + chunk_id + embedding_space_fingerprint
```

Reingerir a mesma identidade atualiza conteúdo, metadata, status e vetor, sem cópia. Isso é **idempotência**: repetir não duplica a mesma versão.

O ID do loader depende de caminho + índice do bloco. Reorganizar parágrafos pode criar IDs diferentes; um loader próprio pode adotar IDs do domínio.

## 23. Alterando o modelo de embedding

Mudar provider, modelo, dimensão, revisão ou parâmetro semântico muda o fingerprint. Versões antigas e novas podem coexistir e não se misturam na busca.

Uma coluna `vector(1536)` rejeita outra dimensão. Modelos com dimensões distintas exigem schema/tabelas compatíveis; o fingerprint resolve identidade lógica, não muda o tipo físico da coluna.

## 24. Tratamento de erros

| Sintoma | Causa provável | Como investigar e corrigir |
|---|---|---|
| Conexão recusada | PostgreSQL parado/host incorreto | Use `pg_isready`; revise host/porta. |
| `type vector does not exist` | pgvector ausente | Execute `CREATE EXTENSION vector`. |
| Relação inexistente | schema não criado | Rode `psql -f schema.sql`. |
| HTTP 401 | API key inválida | Revise variável sem imprimir o segredo; provider pode lançar `ProviderException`. |
| Ollama recusado | serviço/endpoint indisponível | Teste `/api/tags`. |
| Modelo não encontrado | nome não instalado | Confirme no serviço externo. |
| Dimensão incompatível | provider/espaço/tabela discordam | Compare `space()->dimensions` e `vector(n)`; pode ocorrer `InvalidEmbeddingException` ou erro SQL. |
| `Unable to open` | arquivo/caminho/permissão | Corrija caminho; loader lança `RuntimeException`. |
| Resposta sem dados | payload externo inesperado | Investigue status de forma segura; `ProviderException`. |
| Lote com tamanho diferente | provider violou cardinalidade | Executor falha; `IngestionException` preserva relatório parcial. |
| Tenant vazio | escopo obrigatório ausente | Forneça valor não vazio; `InvalidArgumentException`. |
| Nenhuma fonte | banco vazio ou filtro/espaço errado | Confira tenant, collection, status, fingerprint e registros. |

```php
use Omegaalfa\ContextEngine\Exception\IngestionException;

try {
    $report = $ingestion->ingest($loader);
} catch (IngestionException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Chunks já salvos: ' . $error->partialReport->chunksPersisted . PHP_EOL);
}
```

Lotes persistidos permanecem duráveis; uma nova execução pode retomá-los com upsert idempotente.

## 25. Como saber se funciona

```text
[ ] PHP e extensões disponíveis
[ ] PostgreSQL responde
[ ] pgvector instalada
[ ] context_chunks existe
[ ] provider responde
[ ] ingestão gravou chunks
[ ] retrieval retornou fontes
[ ] LLM gerou resposta
```

```bash
php -v
pg_isready -h 127.0.0.1 -d context_engine_example
psql -d context_engine_example -c "SELECT extversion FROM pg_extension WHERE extname='vector';"
psql -d context_engine_example -c "SELECT count(*) FROM context_chunks;"
php example.php
```

Nunca confira credenciais imprimindo-as em logs.

## 26. Exemplo completo final

Configure o ambiente:

```bash
export OPENAI_API_KEY='sua-chave'
export DB_HOST='127.0.0.1'
export DB_PORT='5432'
export DB_DATABASE='context_engine_example'
export DB_USERNAME='context_engine'
export DB_PASSWORD='senha-segura'
```

Salve como `example.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

$env = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Defina a variável {$name}.");
    }
    return $value;
};

try {
    $key = $env('OPENAI_API_KEY');
    $http = new AsyncHttpClient();
    $embeddings = new OpenAIEmbeddingProvider(
        apiKey: $key,
        model: 'text-embedding-3-small',
        dimensions: 1536,
        client: $http,
    );

    $settings = new DatabaseSettings(
        driver: 'pgsql',
        host: $env('DB_HOST'),
        database: $env('DB_DATABASE'),
        port: (int) $env('DB_PORT'),
        username: $env('DB_USERNAME'),
        password: $env('DB_PASSWORD'),
    );
    $store = new PgVectorStore(new QueryBuilder(new PDOConnection($settings)));

    $ingestion = new IngestionPipeline(
        splitter: new RecursiveTextSplitter(chunkSize: 500, overlap: 75),
        embeddings: $embeddings,
        store: $store,
        batchSize: 16,
        executor: new FiberBatchEmbeddingExecutor(
            loop: new FiberEventLoop(),
            concurrency: 4,
        ),
    );
    $report = $ingestion->ingest(new TextFileLoader(
        __DIR__ . '/documents/politica-reembolso.txt',
        'empresa-a',
    ));
    printf("Ingestão completa; %d chunks persistidos.\n", $report->chunksPersisted);

    $retriever = new Retriever(
        embeddings: $embeddings,
        store: $store,
        policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
        collection: 'default',
    );
    $rag = new RagPipeline(
        retriever: $retriever,
        prompts: new ContextPromptBuilder(),
        model: new OpenAILanguageModel(
            apiKey: $key,
            model: 'gpt-4.1-mini',
            client: $http,
        ),
    );

    $answer = $rag->ask(
        'Em quanto tempo devo solicitar um reembolso?',
        'empresa-a',
    );
    echo "\nResposta:\n{$answer->content}\n\nFontes:\n";
    foreach ($answer->sources as $source) {
        printf(
            "- chunk=%s distância=%.4f conteúdo=%s\n",
            $source->chunk->id,
            $source->distance,
            $source->chunk->content,
        );
    }
} catch (IngestionException $error) {
    fwrite(STDERR, "Falha de ingestão: {$error->getMessage()}\n");
    fwrite(STDERR, "Chunks persistidos: {$error->partialReport->chunksPersisted}\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Falha: {$error->getMessage()}\n");
    exit(1);
}
```

Execute `php example.php`. Espere linhas no banco, resposta e fontes. Sem decorator de cache, embeddings são recalculados nas execuções seguintes, embora o upsert evite duplicatas.

## 27. O que faz e o que não faz

| O ContextEngine faz | A aplicação precisa fazer |
|---|---|
| Lê `.txt` | Fornecer arquivos/loaders adicionais |
| Divide documentos | Escolher chunk e overlap |
| Gera embeddings | Configurar OpenAI/Ollama |
| Limita concorrência HTTP | Escolher limite adequado |
| Salva e busca no pgvector | Provisionar banco e schema |
| Isola tenant/collection | Autenticar e escolher tenant |
| Monta prompt/fontes | Definir regras da aplicação |
| Gera resposta OpenAI | Controlar credencial/custo |
| Aceita PSR-16 | Configurar backend de cache |
| Expõe classes PHP | Criar API, CLI ou tela |

## 28. Limitações atuais

- sem interface web, API HTTP ou CLI de aplicação;
- loader nativo apenas para texto; PDF/HTML exigem implementação;
- sem busca híbrida ou reranking;
- providers atuais sem streaming incremental real;
- extensão, tabela e índices não são criados em runtime;
- OpenAI depende de rede/credencial; Ollama depende de serviço/modelo;
- Ollama incluído apenas para embeddings;
- coluna `vector(n)` possui dimensão física fixa;
- nenhum adaptador Redis PSR-16 incluído.

## 29. Próximos passos do iniciante

1. Teste um arquivo e uma collection.
2. Inspecione chunks e fontes.
3. Adicione documentos gradualmente.
4. Crie loader com IDs/collections próprios.
5. Exponha uma pequena API HTTP.
6. Adicione autenticação e tenant derivado do usuário.
7. Configure cache depois de medir repetição.
8. Monitore latência, erros, tokens, custo e qualidade.

## 30. Resumo visual final

```text
Preparar: PHP + Composer + PostgreSQL + pgvector + provider
Ensinar:  carregar + dividir + gerar embeddings + salvar
Perguntar: embedding da pergunta + buscar chunks + montar contexto
Responder: contexto para o modelo + resposta + fontes
```

### O fluxo em dez linhas

1. A aplicação fornece arquivo e tenant.
2. O loader produz documentos.
3. O splitter produz chunks.
4. O batcher agrupa chunks.
5. O provider cria embeddings.
6. O store salva texto, vetor e espaço.
7. A pergunta recebe embedding no mesmo espaço.
8. O retriever busca no escopo correto.
9. O prompt builder organiza pergunta e fontes.
10. O modelo gera `Answer` com conteúdo e fontes.

## Glossário resumido

| Termo | Resumo |
|---|---|
| RAG | Responder após consultar documentos. |
| Ingestão | Preparar e armazenar documentos. |
| Chunk | Parte pequena do documento. |
| Embedding | Números que representam significado. |
| Espaço vetorial | Identidade do mapa dos embeddings. |
| Retrieval | Recuperação dos chunks relevantes. |
| Tenant | Organização isolada. |
| Collection | Categoria dentro do tenant. |
| Provider | Adaptador para serviço. |
| LLM | Modelo que escreve a resposta. |

## Checklist de instalação

```text
[ ] PHP 8.4 e extensões
[ ] Composer
[ ] Repositórios VCS e dependências
[ ] PostgreSQL e pgvector
[ ] Schema com dimensão correta
[ ] Documento de exemplo
[ ] OpenAI ou Ollama para embeddings
[ ] OpenAI para resposta, no exemplo atual
[ ] Variáveis de banco
[ ] example.php executado
```

## Links relevantes

- [ContextEngine](https://github.com/omegaalfa/ContextEngine)
- [README](https://github.com/omegaalfa/ContextEngine/blob/main/README.md)
- [composer.json](https://github.com/omegaalfa/ContextEngine/blob/main/composer.json)
- [Schema de integração](https://github.com/omegaalfa/ContextEngine/blob/main/tests/Integration/Fixtures/postgresql/schema.sql)
- [Docker Compose](https://github.com/omegaalfa/ContextEngine/blob/main/docker-compose.yml)
- [Código](https://github.com/omegaalfa/ContextEngine/tree/main/src) e [testes](https://github.com/omegaalfa/ContextEngine/tree/main/tests)
- [QueryBuilder](https://github.com/omegaalfa/query-builder), [HttpClient](https://github.com/omegaalfa/HttpClient) e [FiberEventLoop](https://github.com/omegaalfa/FiberEventLoop)

## Arquivos consultados

`README.md`, `composer.json`, `composer.lock`, `.env.example`, `docker-compose.yml`, todos os contratos e módulos em `src/`, schema de integração, testes PgVector/Redis e testes unitários de arquitetura, ingestão, cache, retrieval e versionamento.

## Pontos não confirmados

- publicação no Packagist: não localizada em 29/07/2026;
- tag estável: o pacote atual exige `dev-main`;
- dimensão de modelo Ollama: depende do modelo instalado;
- disponibilidade/preço futuro dos modelos OpenAI: depende do serviço;
- adaptador Redis PSR-16 recomendado: o pacote não escolhe um;
- adequação a carga de produção específica: exige validação na aplicação real.
