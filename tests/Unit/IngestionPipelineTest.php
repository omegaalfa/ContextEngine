<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Contract\{BatchEmbeddingExecutor,DocumentLoader,EmbeddingProvider,TextSplitter,VersionedVectorStore};
use Fiber;
use InvalidArgumentException;
use LogicException;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\{EmbeddingBatchRequest,EmbeddingSpace};
use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\{BatchEmbeddingResult, BatchWindowException, DocumentVersion, IngestionPipeline};
use Omegaalfa\ContextEngine\Ingestion\BatchExecutionProgress;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IngestionPipelineTest extends TestCase
{
    public function testEmptyLoaderProducesCompleteEmptyReport(): void
    {
        $loader = new class () implements DocumentLoader {
            public function load(): iterable
            {
                return [];
            }
        };
        $provider = $this->provider();
        $store = new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public function storeBatch(array $chunks): void
            {
                throw new LogicException('must not persist');
            }public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        $report = new IngestionPipeline(new RecursiveTextSplitter(), $provider, $store, $this->executor())->ingest($loader);
        self::assertTrue($report->complete);
        self::assertSame(0, $report->chunksPersisted);
        self::assertSame(0, $report->batchesPersisted);
    }
    public function testStoresBoundedBatches(): void
    {
        $loader = new class () implements DocumentLoader {
            public function load(): iterable
            {
                yield new Document('d', 't', str_repeat('one two three. ', 20));
            }
        };
        $embedding = new class () implements EmbeddingProvider {
            public function space(): EmbeddingSpace
            {
                return new EmbeddingSpace('fake', 'fake', 2);
            } public function embed(string $text, string $tenantId): Embedding
            {
                return new Embedding([1,2], $this->space());
            } public function embedBatch(EmbeddingBatchRequest $request): array
            {
                return array_map(fn () => $this->embed('', $request->tenantId), $request->texts);
            }
        };
        $store = new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public array $sizes = [];
            public int $activated = 0;
            public function storeBatch(array $chunks): void
            {
                $this->sizes[] = count($chunks);
            } public function search(VectorSearchQuery $query): array
            {
                return [];
            }
            public function activateVersion(DocumentVersion $version): void
            {
                $this->activated++;
            }
        };
        $report = new IngestionPipeline(new RecursiveTextSplitter(30, 4), $embedding, $store, $this->executor(), 3)->ingest($loader);
        self::assertGreaterThan(3, $report->chunksPersisted);
        self::assertLessThanOrEqual(3, max($store->sizes));
        self::assertSame($report->chunksPersisted, array_sum($store->sizes));
        self::assertSame(1, $store->activated);
        self::assertSame(1, $report->documentsActivated);
    }
    public function testFinalIncompleteBatchIsPersisted(): void
    {
        $loader = new class () implements DocumentLoader {
            public function load(): iterable
            {
                yield new Document('d', 't', str_repeat('word ', 11));
            }
        };
        $provider = $this->provider();
        $store = new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public array $sizes = [];
            public function storeBatch(array $chunks): void
            {
                $this->sizes[] = count($chunks);
            }public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        new IngestionPipeline(new RecursiveTextSplitter(10, 1), $provider, $store, $this->executor(), 3)->ingest($loader);
        self::assertNotSame(0, end($store->sizes));
        self::assertLessThanOrEqual(3, end($store->sizes));
    }
    public function testPartialFailurePreservesReportAndCause(): void
    {
        $loader = new class () implements DocumentLoader {
            public function load(): iterable
            {
                yield new Document('doc-failed', 'tenant', 'one two three four');
            }
        };
        $provider = $this->provider();
        $store = new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public int $persisted = 0;
            public bool $failed = false;
            public function storeBatch(array $chunks): void
            {
                $this->persisted += count($chunks);
            } public function search(VectorSearchQuery $query): array
            {
                return [];
            }
            public function failVersion(DocumentVersion $version): void
            {
                $this->failed = true;
            }
        };
        $executor = new class () implements BatchEmbeddingExecutor {
            public function execute(iterable $batches, EmbeddingProvider $provider): iterable
            {
                $batch = [...$batches][0];
                yield new BatchEmbeddingResult(0, $batch, array_map(fn () => new Embedding([1], $provider->space()), $batch), new BatchExecutionProgress(3, 3, 1, 0, 3));
                throw new BatchWindowException(1, [1,2], [2], [2], new BatchExecutionProgress(3, 3, 3, 1, 3), new RuntimeException('provider failed'));
            }
        };
        try {
            new IngestionPipeline(new RecursiveTextSplitter(8, 1), $provider, $store, $executor, 1)->ingest($loader);
            self::fail('Expected failure');
        } catch (IngestionException $e) {
            self::assertSame('doc-failed', $e->documentId);
            self::assertSame(1, $e->failedBatchSequence);
            self::assertSame(1, $e->partialReport->batchesPersisted);
            self::assertSame(1, $e->partialReport->chunksPersisted);
            self::assertSame(1, $e->partialReport->batchesDiscarded);
            self::assertFalse($e->partialReport->complete);
            self::assertSame('embedding_batch_failed', $e->partialReport->failure?->code);
            self::assertSame('Embedding generation failed.', $e->partialReport->failure?->message);
            self::assertSame('provider failed', $e->getPrevious()?->getMessage());
            self::assertStringNotContainsString('provider failed', $e->getMessage());
            self::assertTrue($store->failed);
            self::assertSame(1, $e->partialReport->documentVersionsFailed);
            self::assertSame(0, $e->partialReport->documentsActivated);
        }
    }
    public function testRejectsInvalidBatchSizeAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IngestionPipeline(new RecursiveTextSplitter(), $this->provider(), new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        }, $this->executor(), 0);
    }
    public function testDocumentWithoutChunksFailsWithoutActivation(): void
    {
        $loader = new class () implements DocumentLoader {
            public function load(): iterable
            {
                yield new Document('empty-split', 'tenant', 'content');
            }
        };
        $splitter = new class () implements TextSplitter {
            public function fingerprint(): string
            {
                return 'empty-splitter-v1';
            }
            public function split(Document $document): iterable
            {
                return [];
            }
        };
        $store = new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public bool $activated = false;
            public bool $failed = false;
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
                return [];
            }
            public function activateVersion(DocumentVersion $version): void
            {
                $this->activated = true;
            }
            public function failVersion(DocumentVersion $version): void
            {
                $this->failed = true;
            }
        };

        try {
            new IngestionPipeline($splitter, $this->provider(), $store, $this->executor())->ingest($loader);
            self::fail('Expected ingestion failure.');
        } catch (IngestionException $error) {
            self::assertFalse($store->activated);
            self::assertTrue($store->failed);
            self::assertSame(0, $error->partialReport->chunksPersisted);
        }
    }
    public function testPersistenceFailureIsSanitizedAndDrainsStartedEmbeddings(): void
    {
        $loader = new class () implements DocumentLoader {
            public function load(): iterable
            {
                yield new Document('doc', 'tenant', 'one two three four five six');
            }
        };
        $provider = new SuspendingIngestionProvider();
        $store = new class () implements VersionedVectorStore {
            use VectorStoreDeletionStubs;
            public function storeBatch(array $chunks): void
            {
                throw new RuntimeException('database host and SQL must stay private');
            }
            public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        try {
            new IngestionPipeline(new RecursiveTextSplitter(8, 1), $provider, $store, new FiberBatchEmbeddingExecutor(new FiberEventLoop(), 3), 1)->ingest($loader);
            self::fail('Expected persistence failure.');
        } catch (IngestionException $error) {
            self::assertSame('batch_persistence_failed', $error->partialReport->failure?->code);
            self::assertSame('Batch persistence failed.', $error->partialReport->failure?->message);
            self::assertStringNotContainsString('database host', $error->getMessage());
            self::assertSame(0, $error->partialReport->batchesPersisted);
            self::assertSame(3, $error->partialReport->batchesPlanned, 'No batch beyond the failed window may be scheduled.');
            self::assertSame($error->partialReport->batchesStarted, $error->partialReport->batchesCompleted);
            self::assertSame($error->partialReport->batchesPlanned, $error->partialReport->batchesDiscarded);
            self::assertSame(0, $provider->active);
        }
    }
    private function executor(): BatchEmbeddingExecutor
    {
        return new FiberBatchEmbeddingExecutor(new FiberEventLoop());
    }
    private function provider(): EmbeddingProvider
    {
        return new class () implements EmbeddingProvider {
            public function space(): EmbeddingSpace
            {
                return new EmbeddingSpace('fake', 'm', 1);
            }public function embed(string $text, string $tenantId): Embedding
            {
                return new Embedding([1], $this->space());
            }public function embedBatch(EmbeddingBatchRequest $request): array
            {
                return array_map(fn () => new Embedding([1], $this->space()), $request->texts);
            }
        };
    }
}

final class SuspendingIngestionProvider implements EmbeddingProvider
{
    public int $active = 0;
    public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('fake', 'm', 1);
    }
    public function embed(string $text, string $tenantId): Embedding
    {
        return new Embedding([1], $this->space());
    }
    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        $this->active++;
        Fiber::suspend();
        $this->active--;
        return array_map(fn () => new Embedding([1], $this->space()), $request->texts);
    }
}

trait VectorStoreDeletionStubs
{
    public function beginVersion(DocumentVersion $version): void {}

    public function stageBatch(DocumentVersion $version, array $chunks): void
    {
        $this->storeBatch($chunks);
    }

    public function activateVersion(DocumentVersion $version): void {}

    public function failVersion(DocumentVersion $version): void {}

    public function deleteChunk(ChunkDeleteQuery $query): int
    {
        return 0;
    }
    public function deleteDocument(DocumentDeleteQuery $query): int
    {
        return 0;
    }
    public function clearCollection(CollectionDeleteQuery $query): int
    {
        return 0;
    }
}
