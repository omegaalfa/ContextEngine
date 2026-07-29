<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Contract\{BatchEmbeddingExecutor,DocumentLoader,EmbeddingProvider,TextSplitter,VectorStore};
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\{EmbeddingBatchRequest,EmbeddingSpace};
use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Ingestion\{BatchEmbeddingResult,BatchWindowException,IngestionPipeline};
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use PHPUnit\Framework\TestCase;

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
        $store = new class () implements VectorStore {
            public function storeBatch(array $chunks): void
            {
                throw new \LogicException('must not persist');
            }public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        $report = new IngestionPipeline(new RecursiveTextSplitter(), $provider, $store)->ingest($loader);
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
        $store = new class () implements VectorStore {
            public array $sizes = [];
            public function storeBatch(array $chunks): void
            {
                $this->sizes[] = count($chunks);
            } public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        $report = new IngestionPipeline(new RecursiveTextSplitter(30, 4), $embedding, $store, 3)->ingest($loader);
        self::assertGreaterThan(3, $report->chunksPersisted);
        self::assertLessThanOrEqual(3, max($store->sizes));
        self::assertSame($report->chunksPersisted, array_sum($store->sizes));
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
        $store = new class () implements VectorStore {
            public array $sizes = [];
            public function storeBatch(array $chunks): void
            {
                $this->sizes[] = count($chunks);
            }public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        new IngestionPipeline(new RecursiveTextSplitter(10, 1), $provider, $store, 3)->ingest($loader);
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
        $store = new class () implements VectorStore {
            public int $persisted = 0;
            public function storeBatch(array $chunks): void
            {
                $this->persisted += count($chunks);
            } public function search(VectorSearchQuery $query): array
            {
                return [];
            }
        };
        $executor = new class () implements BatchEmbeddingExecutor {
            public function execute(iterable $batches, EmbeddingProvider $provider): iterable
            {
                $batch = [...$batches][0];
                yield new BatchEmbeddingResult(0, $batch, array_map(fn () => new Embedding([1], $provider->space()), $batch));
                throw new BatchWindowException(1, [1,2], [2], [2], [1 => 1,2 => 1], new \RuntimeException('provider failed'));
            }
        };
        try {
            new IngestionPipeline(new RecursiveTextSplitter(8, 1), $provider, $store, 1, executor:$executor)->ingest($loader);
            self::fail('Expected failure');
        } catch (IngestionException $e) {
            self::assertSame('doc-failed', $e->documentId);
            self::assertSame(1, $e->failedBatchSequence);
            self::assertSame(1, $e->partialReport->batchesPersisted);
            self::assertSame(1, $e->partialReport->chunksPersisted);
            self::assertSame(1, $e->partialReport->batchesDiscarded);
            self::assertFalse($e->partialReport->complete);
            self::assertSame('provider failed', $e->getPrevious()?->getMessage());
        }
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
