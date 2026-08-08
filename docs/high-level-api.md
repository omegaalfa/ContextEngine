# 🚀 High-Level API do ContextEngine

> Um único ponto de entrada para configurar ingestão, busca e RAG sem montar providers, executores, stores e pipelines manualmente.

## ✨ Ideia principal

Toda aplicação começa por:

```php
use Omegaalfa\ContextEngine\ContextEngine;

$engine = ContextEngine::create();
```

`create()` lê as variáveis de ambiente já carregadas no processo, aplica os valores padrão da biblioteca e permite sobrescrever qualquer configuração pela API fluente.

```text
ambiente + defaults
        ↓
ContextEngine::create()
        ↓
overrides fluentes
        ↓
build()
        ↓
ContextEngineContext
├── ingest()
├── search()
├── ask()
└── stream()
```

> A biblioteca não procura `.env` automaticamente. Use o carregador de ambiente da aplicação antes de chamar `create()`.

## 🧭 Quando usar

| Use a High-Level API quando... | Prefira composição manual quando... |
|---|---|
| quer começar rapidamente | precisa substituir contratos internos específicos |
| usa Ollama ou OpenAI com pgvector | utiliza outro vector store |
| prefere configuração fluente | possui um container de dependências próprio |
| quer defaults consistentes | controla manualmente event loop e infraestrutura |

## ⚡ Primeiro engine

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\ContextEngine;

$engine = ContextEngine::create()
    ->tenant('empresa')
    ->collection('algorithms')
    ->status('active')
    ->ollama(
        baseUrl: 'http://127.0.0.1:11434',
        embeddingModel: 'bge-m3',
        languageModel: 'llama3.1:8b',
        embeddingDimensions: 1_024,
    )
    ->build();
```

## ⚙️ Configuração

### Ambiente

```dotenv
CONTEXT_ENGINE_PGVECTOR_HOST=127.0.0.1
CONTEXT_ENGINE_PGVECTOR_PORT=54339
CONTEXT_ENGINE_PGVECTOR_DATABASE=context_engine
CONTEXT_ENGINE_PGVECTOR_USERNAME=context_engine
CONTEXT_ENGINE_PGVECTOR_PASSWORD=context_engine

CONTEXT_ENGINE_OLLAMA_URL=http://127.0.0.1:11434
CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL=bge-m3
CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS=1024

CONTEXT_ENGINE_COLLECTION=default
CONTEXT_ENGINE_STATUS=active
```

Depois de carregar essas variáveis no processo:

```php
$engine = ContextEngine::create()->build();
```

### Overrides fluentes

Overrides têm precedência sobre o ambiente:

```php
$engine = ContextEngine::create()
    ->tenant('empresa-b')
    ->collection('contracts')
    ->status('active')
    ->build();
```

### Ollama

```php
$engine = ContextEngine::create()
    ->ollama(
        baseUrl: 'http://127.0.0.1:11434',
        embeddingModel: 'bge-m3',
        languageModel: 'llama3.1:8b',
        embeddingDimensions: 1_024,
    )
    ->build();
```

### OpenAI

```php
$engine = ContextEngine::create()
    ->openAi(
        apiKey: getenv('OPENAI_API_KEY'),
        model: 'text-embedding-3-small',
    )
    ->openAiLanguageModel(
        apiKey: getenv('OPENAI_API_KEY'),
        model: 'gpt-4.1-mini',
    )
    ->build();
```

## 📥 Ingestão

```php
$engine = ContextEngine::create()
    ->ingestion(
        batchSize: 32,
        concurrency: 4,
        chunkSize: 1_200,
        chunkOverlap: 0,
    )
    ->build();

$report = $engine->ingest($loader);
```

| Opção | Função |
|---|---|
| `batchSize` | quantidade de chunks enviada por lote |
| `concurrency` | máximo de chamadas concorrentes ao provider |
| `chunkSize` | limite máximo usado pelo chunker estrutural |
| `chunkOverlap` | mantido por compatibilidade; chunking estrutural prefere blocos naturais |

### PDF estrutural

A High-Level API monta embedding provider, executor, versionamento e pgvector. O loader permanece explícito porque representa a origem do documento.

```php
use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;

$tenantId = 'empresa';
$collection = 'algorithms';

$engine = ContextEngine::create()
    ->tenant($tenantId)
    ->collection($collection)
    ->ingestion(
        batchSize: 32,
        concurrency: 4,
        chunkSize: 1_200,
        chunkOverlap: 0,
    )
    ->build();

$loader = new PdfDocumentLoader(
    path: '/documentos/livro.pdf',
    tenantId: $tenantId,
    extractor: new PopplerPdfTextExtractor(timeoutSeconds: 120),
    collection: $collection,
    pagesPerDocument: PHP_INT_MAX,
    metadata: [
        'title' => 'Algoritmos e estrutura de dados em PHP',
        'content_kind' => 'book',
    ],
);

$report = $engine->ingest($loader);
```

```text
livro completo
      ↓
PdfParser
      ↓
headings + parágrafos + código + listas + tabelas
      ↓
chunks estruturais
      ↓
page_start / page_end como metadata
```

`pagesPerDocument: PHP_INT_MAX` faz o livro formar um único documento lógico. Páginas deixam de ser fronteiras e passam a representar apenas proveniência.

## 🔎 Busca

```php
$engine = ContextEngine::create()
    ->collection('algorithms')
    ->retrieval(
        heuristicQueryPlanning: true,
        retrievalLimit: 30,
        lexicalCandidateLimit: 30,
        fusedLimit: 30,
        contextChunkLimit: 5,
        maximumDistance: 0.60,
        hybridSearch: true,
        textSearchConfiguration: 'portuguese',
    )
    ->build();

$results = $engine->search(
    'Como funciona o algoritmo quicksort?',
    tenantId: 'empresa',
);
```

Os limites representam etapas diferentes: `retrievalLimit` controla candidatos vetoriais, `lexicalCandidateLimit` controla candidatos textuais, `fusedLimit` limita o RRF e `contextChunkLimit` limita as fontes finais. Quando a aplicação injeta um reranker customizado, `rerankerCandidateLimit` limita o lote entregue a ele. Todos os novos argumentos são opcionais; configurações antigas continuam válidas.

`textSearchConfiguration` é o idioma/configuração full-text do PostgreSQL. Use somente uma configuração instalada, como `portuguese`, `english` ou `simple`. O valor é validado antes de entrar no SQL.

```php
foreach ($results as $result) {
    $chunk = $result->chunk;

    echo 'Páginas: '
        . ($chunk->metadata['page_start'] ?? '?')
        . '–'
        . ($chunk->metadata['page_end'] ?? '?')
        . PHP_EOL;

    echo $chunk->content . PHP_EOL;
}
```

## 🧠 Respostas RAG

### Resposta completa

```php
$answer = $engine->ask(
    'Explique a complexidade do quicksort.',
    tenantId: 'empresa',
);

echo $answer->content;
```

### Streaming

```php
foreach ($engine->stream(
    'Explique a complexidade do quicksort.',
    tenantId: 'empresa',
) as $delta) {
    if ($delta->final) {
        break;
    }

    echo $delta->content;
}
```

| Método | Retorno | Indicado para |
|---|---|---|
| `search()` | lista de resultados | busca e inspeção de evidências |
| `ask()` | resposta completa | APIs tradicionais e jobs |
| `stream()` | deltas incrementais | chats, CLI e interfaces responsivas |
| `searchWithDiagnostics()` | resultados diagnosticáveis | depuração do retrieval |
| `askWithDiagnostics()` | resposta e diagnóstico | observabilidade RAG |

Streaming depende de um provider que implemente `StreamingLanguageModel`.

## 🏗️ O que `build()` cria

```text
ContextEngine::create()
        ↓
      build()
        ↓
ContextEngineContext
├── Retriever
├── IngestionPipeline
├── RagPipeline
├── EmbeddingProvider
└── VectorStore
```

A High-Level API não substitui a arquitetura interna. Ela apenas centraliza sua composição.

## ✅ Boas práticas

- use somente `ContextEngine::create()` como ponto de entrada;
- carregue `.env` na borda da aplicação, nunca dentro do domínio;
- mantenha tenant e collection explícitos também nos loaders;
- use o mesmo modelo e dimensão de embeddings na ingestão e na busca;
- trate livros e PDFs longos como um documento lógico único;
- comece com chunks entre 800 e 1.500 caracteres e ajuste com métricas reais;
- use diagnósticos antes de aumentar indiscriminadamente o limite de retrieval.
- calibre `maximumDistance` com perguntas válidas e inválidas; resultados acima do limite não devem chegar ao modelo.

## 🧪 Exemplos executáveis

```bash
php examples/structural-ingestion/11-high-level-ingest-pdf.php all 1200
php examples/structural-ingestion/12-high-level-search-book.php "Como funciona o quicksort?"
php examples/structural-ingestion/13-high-level-ask-book.php "Como funciona o quicksort?"
```

Consulte [os exemplos de ingestão estrutural](../examples/structural-ingestion/README.md) para parsing, chunking, PDF, ingestão e busca em etapas separadas.
