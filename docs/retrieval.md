# Retrieval

## Tipos

- `RetrievalPolicy(int $limit = 5, VectorMetric $metric = COSINE, ?float $maximumDistance = null)`: limit 1–100; distância máxima não negativa.
- `VectorMetric`: `L2`, `INNER_PRODUCT`, `COSINE`, `L1`.
- `VectorSearchQuery(string $tenantId, Embedding $embedding, RetrievalPolicy $policy = new RetrievalPolicy(), ?string $collection = null, string $status = 'active')`.
- `VectorSearchResult(Chunk $chunk, float $distance)`: distância finita e não negativa.
- `Retriever(EmbeddingProvider $embeddings, VectorStore $store, RetrievalPolicy $policy = ..., ?string $collection = null, string $status = 'active')`.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\{RetrievalPolicy,Retriever,VectorMetric};

$retriever = new Retriever(
    embeddings: $embeddingProvider,
    store: $vectorStore,
    policy: new RetrievalPolicy(limit: 8, metric: VectorMetric::COSINE),
    collection: 'manuals',
    status: 'active',
);

$results = $retriever->retrieve(new Question('Como configurar?', 'tenant-42'));
```

Cosine compara direção e é comum para embeddings normalizados. L2 mede distância euclidiana; inner product favorece produto maior conforme a semântica pgvector; L1 soma diferenças absolutas. Use a métrica compatível com modelo e índice. `distance` não é probabilidade.

Collection `null` omite esse filtro; tenant e status permanecem obrigatórios. `maximumDistance` é aplicado após as linhas compatíveis retornarem do banco, enquanto identidade/espaço são filtrados no SQL.
