<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Infrastructure\Ingestion;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\{BatchEmbeddingExecutor,EmbeddingProvider};
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Ingestion\{BatchEmbeddingResult,BatchWindowException};
use Omegaalfa\FiberEventLoop\{FiberEventLoop,Future};
use Throwable;

final readonly class FiberBatchEmbeddingExecutor implements BatchEmbeddingExecutor
{
    public function __construct(private FiberEventLoop $loop = new FiberEventLoop(), private int $concurrency = 4)
    {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Concurrency must be positive.');
        }
    }
    /** @param iterable<non-empty-list<Chunk>> $batches @return iterable<BatchEmbeddingResult> */
    public function execute(iterable $batches, EmbeddingProvider $provider): iterable
    {
        $iterator = (function () use ($batches) {
            yield from $batches;
        })();
        $iterator->rewind();
        $sequence = 0;
        while ($iterator->valid()) {
            /** @var array<int,array{future:Future,chunks:non-empty-list<Chunk>}> $window */ $window = [];
            while (count($window) < $this->concurrency && $iterator->valid()) {
                $chunks = $iterator->current();
                $seq = $sequence++;
                $request = new EmbeddingBatchRequest($chunks[0]->tenantId, array_map(static fn (Chunk $chunk): string => $chunk->content, $chunks), $provider->space(), ['batch_sequence' => $seq]);
                $window[$seq] = ['future' => $this->loop->async(static fn (): array => $provider->embedBatch($request)), 'chunks' => $chunks];
                $iterator->next();
            }
            $failure = null;
            $failedSequence = null;
            $completed = [];
            $discarded = [];
            foreach ($window as $seq => $operation) {
                try {
                    $embeddings = $operation['future']->await();
                    if (!is_array($embeddings) || !array_is_list($embeddings) || count($embeddings) !== count($operation['chunks'])) {
                        throw new \LogicException('Embedding provider returned a different batch size.');
                    }
                    foreach ($embeddings as $embedding) {
                        if (!$embedding instanceof \Omegaalfa\ContextEngine\Embedding\Embedding) {
                            throw new \LogicException('Embedding provider returned an invalid item.');
                        }
                    }
                    $completed[] = $seq;
                    if ($failure === null) { /** @var non-empty-list<\Omegaalfa\ContextEngine\Embedding\Embedding> $embeddings */ yield new BatchEmbeddingResult($seq, $operation['chunks'], $embeddings);
                    } else {
                        $discarded[] = $seq;
                    }
                } catch (Throwable $e) {
                    if ($failure === null) {
                        $failure = $e;
                        $failedSequence = $seq;
                    } else {
                        $discarded[] = $seq;
                    }
                }
            }
            if ($failure !== null && $failedSequence !== null) {
                throw new BatchWindowException($failedSequence, array_keys($window), $completed, $discarded, array_map(static fn (array $op): int => count($op['chunks']), $window), $failure);
            }
        }
    }
}
