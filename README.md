# Ω ContextEngine

> 🧠 RAG tipado para PHP 8.4 · 🐘 PostgreSQL/pgvector · 🧵 Fibers · 🔒 multi-tenant

Biblioteca RAG oficial do ecossistema Omegaalfa para PHP 8.4. Ela organiza carregamento, chunking, embeddings, persistência pgvector, retrieval, construção de prompt e geração de resposta sem expor concorrência assíncrona na API de aplicação.

## ✨ Recursos

- domínio imutável com tenant, collection e identidade vetorial explícitos;
- splitter recursivo por parágrafo, linha, sentença, palavra e caractere;
- ingestão em lotes com janela limitada de Fibers e persistência serial;
- OpenAI e Ollama para embeddings; OpenAI buffered para respostas;
- pgvector através das APIs tipadas de `omegaalfa/query-builder`;
- caches PSR-16 como decorators;
- falha parcial diagnosticável e streaming somente quando incremental de verdade.

## 📦 Requisitos e instalação

- PHP `^8.4`, PDO e sockets;
- `omegaalfa/query-builder`, `omegaalfa/http-client`, `omegaalfa/fiber-event-loop` em `dev-main` nesta fase;
- PostgreSQL com pgvector para `PgVectorStore`.

```bash
composer require omegaalfa/context-engine
```

Durante desenvolvimento local, o `composer.json` usa repositories `path` para os três pacotes Omegaalfa irmãos. Uma distribuição Packagist não deve exigir esses caminhos no projeto consumidor.

## 🔄 Primeiro fluxo

```text
DocumentLoader → TextSplitter → Chunk → EmbeddingProvider
→ EmbeddedChunk → VectorStore → Retriever → Prompt → LanguageModel → Answer
```

### Ingestão

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;

// $embeddingProvider e $vectorStore são composições descritas na documentação.
$pipeline = new IngestionPipeline(
    splitter: new RecursiveTextSplitter(chunkSize: 1_000, overlap: 150),
    embeddings: $embeddingProvider,
    store: $vectorStore,
    batchSize: 32,
);

$report = $pipeline->ingest(
    new TextFileLoader('/data/manual.txt', 'tenant-42'),
);
```

### Pergunta e resposta

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;

$rag = new RagPipeline(
    retriever: new Retriever($embeddingProvider, $vectorStore, collection: 'docs'),
    prompts: new ContextPromptBuilder(),
    model: $languageModel,
);

$answer = $rag->ask('Qual é o prazo de retenção?', 'tenant-42');
echo $answer->content;
```

## 🧩 Infraestrutura suportada

- **Embeddings:** `OpenAIEmbeddingProvider`, `OllamaEmbeddingProvider`.
- **LLM:** `OpenAILanguageModel`, com resposta buffered.
- **Banco vetorial:** PostgreSQL + pgvector.
- **Cache:** qualquer PSR-16; Redis é apenas infraestrutura opt-in de integração.
- **Concorrência:** `FiberBatchEmbeddingExecutor`, padrão de quatro lotes por janela.
- **Streaming:** contrato disponível, mas nenhum provider HTTP incluído implementa streaming incremental atualmente.

## 🗺️ Documentação

- [Índice completo](docs/index.md)
- [Primeiros passos](docs/getting-started.md)
- [Instalação](docs/installation.md)
- [Arquitetura](docs/architecture.md)
- [Conceitos](docs/core-concepts.md)
- [Ingestão](docs/ingestion.md)
- [Embeddings](docs/embeddings.md)
- [PgVectorStore](docs/vector-store.md) e [schema](docs/database-schema.md)
- [Retrieval](docs/retrieval.md) e [pipeline RAG](docs/rag-pipeline.md)
- [Providers](docs/providers.md), [cache](docs/caching.md), [concorrência](docs/concurrency.md) e [streaming](docs/streaming.md)
- [Referência da API](docs/api-reference.md)
- [Segurança](docs/security.md), [erros](docs/error-handling.md) e [troubleshooting](docs/troubleshooting.md)

## ✅ Testes

```bash
composer validate --strict
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
docker compose --env-file .env.example --profile integration config --quiet
```

Integrações são opt-in. Consulte [Docker e integração](docs/docker-integration.md).

## ⚠️ Limitações

- O HttpClient materializa a resposta; OpenAI não anuncia streaming incremental.
- A dimensão `vector(n)` deve ser provisionada para o espaço usado.
- Extensão, tabela, migrations e índices não são criados em runtime.
- Conteúdo é repetido quando um chunk é armazenado em espaços diferentes.
- Loaders estruturados, Gemini, Anthropic, reranking e busca híbrida estão no roadmap.

## 📄 Licença

[MIT](LICENSE).
