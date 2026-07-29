<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Fiber;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\{Embedding,EmbeddingBatchRequest,EmbeddingSpace};
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\BatchWindowException;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use PHPUnit\Framework\TestCase;

final class FiberBatchEmbeddingExecutorTest extends TestCase
{
    public function testConcurrencyIsBoundedAndResultsKeepSequence(): void
    {
        $provider = new SuspendingProvider();
        $batches = [];
        for ($i = 0;$i < 5;$i++) {
            $batches[] = [new Chunk("c$i", 'd', 't', "text$i", $i)];
        }
        $results = iterator_to_array(new FiberBatchEmbeddingExecutor(concurrency:2)->execute($batches, $provider));
        self::assertSame(2, $provider->maximumActive);
        self::assertSame([0,1,2,3,4], array_map(fn ($r) => $r->sequence, $results));
        self::assertSame(['text0','text1','text2','text3','text4'], array_map(fn ($r) => $r->chunks[0]->content, $results));
    }
    public function testOutOfOrderCompletionsRemainAssociatedWithTheirBatch(): void
    {
        $loop = new FiberEventLoop();
        $provider = new DelayedProvider($loop);
        $batches = [];
        for ($i = 0;$i < 3;$i++) {
            $batches[] = [new Chunk("c$i", 'd', 't', "text$i", $i)];
        }
        $results = iterator_to_array(new FiberBatchEmbeddingExecutor($loop, 3)->execute($batches, $provider));
        self::assertNotSame([0,1,2], $provider->completionOrder);
        self::assertSame([0,1,2], array_map(fn ($result) => $result->sequence, $results));
        self::assertSame([0.0,1.0,2.0], array_map(fn ($result) => $result->embeddings[0]->values[0], $results));
    }
    public function testFailureDrainsAndDiscardsRemainderOfWindow(): void
    {
        $provider = new SuspendingProvider(failAt:1);
        $batches = [];
        for ($i = 0;$i < 3;$i++) {
            $batches[] = [new Chunk("c$i", 'd', 't', "text$i", $i)];
        }
        try {
            iterator_to_array(new FiberBatchEmbeddingExecutor(concurrency:3)->execute($batches, $provider));
            self::fail('Expected failure');
        } catch (BatchWindowException $e) {
            self::assertSame(1, $e->failedSequence);
            self::assertSame([0,1,2], $e->started);
            self::assertSame([2], $e->discarded);
            self::assertSame(0, $provider->active);
        }
    }

    public function testProviderCardinalityMismatchFailsTheBatch(): void
    {
        $provider = new class () implements EmbeddingProvider {
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
                return [];
            }
        };
        $this->expectException(BatchWindowException::class);
        iterator_to_array(new FiberBatchEmbeddingExecutor(concurrency: 1)->execute([[new Chunk('c', 'd', 't', 'text', 0)]], $provider));
    }
}
final class DelayedProvider implements EmbeddingProvider
{
    public array $completionOrder = [];
    public function __construct(private FiberEventLoop $loop) {}
    public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('fake', 'delayed', 1);
    }
    public function embed(string $text, string $tenantId): Embedding
    {
        return new Embedding([0], $this->space());
    }
    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        $sequence = (int)$request->metadata['batch_sequence'];
        $this->loop->sleep([0 => 0.003,1 => 0.001,2 => 0.002][$sequence]);
        $this->completionOrder[] = $sequence;
        return [new Embedding([$sequence], $this->space())];
    }
}
final class SuspendingProvider implements EmbeddingProvider
{
    public int $active = 0;
    public int $maximumActive = 0;
    public function __construct(private ?int $failAt = null) {} public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('fake', 'm', 1);
    } public function embed(string $text, string $tenantId): Embedding
    {
        return new Embedding([1], $this->space());
    }
    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        $this->active++;
        $this->maximumActive = max($this->maximumActive, $this->active);
        Fiber::suspend();
        $sequence = $request->metadata['batch_sequence'] ?? -1;
        $this->active--;
        if ($sequence === $this->failAt) {
            throw new \RuntimeException('boom');
        }return array_map(fn () => new Embedding([1], $this->space()), $request->texts);
    }
}
