# Ingestão

```text
DocumentLoader → TextSplitter → Chunk → Batcher
→ BatchEmbeddingExecutor → EmbeddingProvider
→ validação → EmbeddedChunk → VectorStore → IngestionReport
```

## IngestionPipeline

```text
__construct(
    TextSplitter $splitter,
    EmbeddingProvider $embeddings,
    VectorStore $store,
    int $batchSize = 32,
    Batcher $batcher = new Batcher(),
    BatchEmbeddingExecutor $executor = new FiberBatchEmbeddingExecutor()
)
ingest(DocumentLoader $loader): IngestionReport
```

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;

$ingestion = new IngestionPipeline(
    splitter: new RecursiveTextSplitter(800, 100),
    embeddings: $embeddingProvider,
    store: $vectorStore,
    batchSize: 24,
    executor: $batchExecutor,
);

$report = $ingestion->ingest(
    new TextFileLoader('/data/knowledge.txt', 'tenant-42'),
);
```

O pipeline nunca materializa todos os chunks: o `Batcher` produz listas não vazias, inclusive o último lote incompleto. Cada resultado deve ter a mesma cardinalidade e ordem do request e pertencer ao espaço declarado pelo provider. Persistência ocorre serialmente depois da validação e fora das chamadas HTTP.

No exemplo, `$batchExecutor` foi composto no bootstrap. Se `$embeddingProvider` usa `AsyncHttpClient`, crie um único `FiberEventLoop` e injete-o tanto no cliente quanto no `FiberBatchEmbeddingExecutor`. Consulte [Concorrência e backpressure](concurrency.md).

## IngestionReport

Campos públicos readonly:

| Campo | Significado |
|---|---|
| `batchesPlanned` | lotes conhecidos/iniciados até o encerramento |
| `batchesStarted` | operações iniciadas |
| `batchesCompleted` | operações concluídas |
| `batchesPersisted` | lotes gravados serialmente |
| `batchesDiscarded` | concluídos após a primeira falha e descartados |
| `chunksProduced` | chunks puxados para processamento |
| `chunksSent` | chunks enviados ao provider |
| `chunksPersisted` | chunks gravados |
| `firstFailure` | mensagem original ou `null` |
| `affectedBatchSequences` | sequências falha/descartadas |
| `complete` | ingestão terminou sem falha |

## Falha parcial

Ao primeiro erro, nenhum novo lote da próxima janela é iniciado. Futures já iniciados são drenados para liberar recursos; resultados posteriores são descartados. `IngestionException` preserva `previous`, `partialReport`, `documentId`, `space` e `failedBatchSequence`. Uma nova execução é segura porque o store faz upsert pela identidade composta.
