# Primeiros passos

## Objetivo

ContextEngine separa o fluxo RAG em contratos. A aplicação escolhe loaders, providers, banco e cache durante a composição; domínio e pipeline não conhecem controllers, autenticação ou sessões.

## Sequência mínima

1. Provisione PostgreSQL/pgvector conforme [database-schema.md](database-schema.md).
2. Construa um `QueryBuilder` real e um `PgVectorStore`.
3. Escolha um `EmbeddingProvider` cujo `EmbeddingSpace` corresponda a `vector(n)`.
4. Ingira documentos.
5. Construa `Retriever`, `ContextPromptBuilder` e `RagPipeline`.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;

$apiKey = (string) getenv('OPENAI_API_KEY');
$embeddings = new OpenAIEmbeddingProvider(
    apiKey: $apiKey,
    model: 'text-embedding-3-small',
    dimensions: 1536,
);
$languageModel = new OpenAILanguageModel(apiKey: $apiKey);
```

Esse bloco requer credenciais e rede. O cliente HTTP é criado pelo provider; a API pública permanece síncrona.

## Próximos passos

Leia [ingestion.md](ingestion.md), [retrieval.md](retrieval.md) e [rag-pipeline.md](rag-pipeline.md). Para desenvolvimento local determinístico, use [docker-integration.md](docker-integration.md).
