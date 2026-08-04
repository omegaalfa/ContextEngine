# Retrieval

> Leia também: [pipeline avançada, RRF, vizinhos e diagnósticos](retrieval-pipeline.md) e [protocolo de prompt v3](prompt-protocol.md).

## Tipos

- `RetrievalPolicy(int $limit = 5, VectorMetric $metric = COSINE, ?float $maximumDistance = null)`: limit 1–100; distância máxima não negativa.
- `VectorMetric`: `L2`, `INNER_PRODUCT`, `COSINE`, `L1`.
- `VectorSearchQuery(string $tenantId, Embedding $embedding, RetrievalPolicy $policy = new RetrievalPolicy(), ?string $collection = null, string $status = 'active')`.
- `VectorSearchResult(Chunk $chunk, float $distance, ?string $documentVersion = null, bool $neighbor = false, ?float $fusionScore = null, array $matches = [], ?VersionedSourceProvenance $provenance = null)`: distância finita e não negativa, com metadata opcional de versão documental para auditoria e prompt.
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

## Exemplo executável

Depois de executar `examples/simple-ingestion.php`, faça uma busca sem chamar LLM:

```bash
php examples/simple-search.php "Em quanto tempo posso solicitar um reembolso?"
```

O script usa por padrão tenant `empresa-exemplo`, collection `default`, Ollama `bge-m3`/1024 e o PostgreSQL do Compose. É possível alterar `CONTEXT_ENGINE_TENANT_ID`, `CONTEXT_ENGINE_COLLECTION`, `CONTEXT_ENGINE_OLLAMA_URL` e as variáveis `CONTEXT_ENGINE_PGVECTOR_*` pelo ambiente.

O resultado apresenta distância, documento, chunk e conteúdo. Distância menor significa maior proximidade para a métrica cosseno utilizada; ela não representa porcentagem de confiança.
