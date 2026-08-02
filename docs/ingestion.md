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
    BatchEmbeddingExecutor $executor,
    int $batchSize = 32,
    Batcher $batcher = new Batcher()
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

O pipeline nunca materializa todos os chunks: o `Batcher` produz listas não vazias, inclusive o último lote incompleto. Cada resultado deve ter a mesma cardinalidade e ordem do request e pertencer ao espaço declarado pelo provider. Persistência ocorre serialmente depois da validação e fora das chamadas HTTP. Cada `stageBatch()` é atômico e invisível à busca; depois do último lote, `activateVersion()` troca a versão pesquisável em uma transação curta.

No exemplo, `$batchExecutor` foi composto no bootstrap. Se `$embeddingProvider` usa `AsyncHttpClient`, crie um único `FiberEventLoop` e injete-o tanto no cliente quanto no `FiberBatchEmbeddingExecutor`. Consulte [Concorrência e backpressure](concurrency.md).

## IngestionReport

Campos públicos readonly:

| Campo | Significado |
|---|---|
| `batchesPlanned` | lotes retirados da entrada e admitidos para processamento; não materializa toda a entrada |
| `batchesStarted` | operações iniciadas |
| `batchesCompleted` | operações concluídas |
| `batchesPersisted` | lotes gravados serialmente |
| `batchesDiscarded` | concluídos após a primeira falha e descartados |
| `chunksProduced` | chunks puxados para processamento |
| `chunksSent` | chunks enviados ao provider |
| `chunksPersisted` | chunks gravados |
| `failure` | código e mensagem pública sanitizada, ou `null` |
| `affectedBatchSequences` | sequências falha/descartadas |
| `complete` | ingestão terminou sem falha |
| `documentsActivated` | versões de documento tornadas pesquisáveis |
| `documentVersionsFailed` | tentativas staged que falharam |

## Falha parcial

Ao primeiro erro, nenhum novo lote da próxima janela é iniciado. Futures já iniciados são drenados para liberar recursos; resultados posteriores são descartados. A tentativa vira `failed`, não aparece no retrieval e não substitui a versão ativa anterior. `IngestionException` preserva `previous`, `partialReport`, `documentId`, `space` e `failedBatchSequence`. Uma nova execução limpa a tentativa determinística incompleta e a refaz sem duplicação.

`IngestionReport::failure` contém somente código e mensagem seguros para a aplicação. A mensagem original de banco ou provider não é copiada para o relatório nem para a mensagem pública de `IngestionException`; ela permanece acessível como causa para logging controlado.
