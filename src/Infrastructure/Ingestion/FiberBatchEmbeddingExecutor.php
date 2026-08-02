<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Infrastructure\Ingestion;

use InvalidArgumentException;
use LogicException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\BatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Ingestion\BatchEmbeddingResult;
use Omegaalfa\ContextEngine\Ingestion\BatchExecutionProgress;
use Omegaalfa\ContextEngine\Ingestion\BatchWindowException;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\FiberEventLoop\Future;
use Throwable;

final readonly class FiberBatchEmbeddingExecutor implements BatchEmbeddingExecutor
{
    public function __construct(private FiberEventLoop $loop, private int $concurrency = 4)
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('Concurrency must be positive.');
        }
    }

    /**
     * @param iterable<non-empty-list<Chunk>> $batches
     * @return iterable<BatchEmbeddingResult>
     */
    public function execute(iterable $batches, EmbeddingProvider $provider): iterable
    {
        $iterator = (function () use ($batches): iterable {
            yield from $batches;
        })();
        $iterator->rewind();

        $scheduled = 0;
        $started = 0;
        $completedCount = 0;
        $discardedCount = 0;
        $chunksScheduled = 0;

        while ($iterator->valid()) {
            /** @var array<int, array{future: Future, chunks: non-empty-list<Chunk>}> $window */
            $window = [];
            while (count($window) < $this->concurrency && $iterator->valid()) {
                $chunks = $iterator->current();
                $sequence = $scheduled;
                $request = new EmbeddingBatchRequest(
                    $chunks[0]->tenantId,
                    array_map(static fn (Chunk $chunk): string => $chunk->content, $chunks),
                    $provider->space(),
                    ['batch_sequence' => $sequence],
                );
                $window[$sequence] = [
                    'future' => $this->loop->async(static fn (): array => $provider->embedBatch($request)),
                    'chunks' => $chunks,
                ];
                $scheduled++;
                $started++;
                $chunksScheduled += count($chunks);
                $iterator->next();
            }

            $failure = null;
            $failedSequence = null;
            $completed = [];
            $discarded = [];
            $settled = [];

            try {
                foreach ($window as $sequence => $operation) {
                    $result = null;
                    try {
                        $embeddings = $operation['future']->await();
                        $settled[$sequence] = true;
                        $completedCount++;
                        $this->validateEmbeddings($embeddings, count($operation['chunks']));
                        $completed[] = $sequence;

                        if ($failure === null) {
                            /** @var non-empty-list<Embedding> $embeddings */
                            $result = new BatchEmbeddingResult(
                                $sequence,
                                $operation['chunks'],
                                $embeddings,
                                $this->progress($scheduled, $started, $completedCount, $discardedCount, $chunksScheduled),
                            );
                        } else {
                            $discarded[] = $sequence;
                            $discardedCount++;
                        }
                    } catch (Throwable $error) {
                        if (!isset($settled[$sequence])) {
                            $settled[$sequence] = true;
                            $completedCount++;
                        }
                        if ($failure === null) {
                            $failure = $error;
                            $failedSequence = $sequence;
                        }
                    }
                    if ($result !== null) {
                        yield $result;
                    }
                }
            } finally {
                foreach ($window as $sequence => $operation) {
                    if (isset($settled[$sequence])) {
                        continue;
                    }
                    try {
                        $operation['future']->await();
                    } catch (Throwable) {
                        // Draining is best-effort here; the consumer's exception remains primary.
                    }
                }
            }

            if ($failure !== null && $failedSequence !== null) {
                throw new BatchWindowException(
                    $failedSequence,
                    array_keys($window),
                    $completed,
                    $discarded,
                    $this->progress($scheduled, $started, $completedCount, $discardedCount, $chunksScheduled),
                    $failure,
                );
            }
        }
    }

    private function validateEmbeddings(mixed $embeddings, int $expected): void
    {
        if (!is_array($embeddings) || !array_is_list($embeddings) || count($embeddings) !== $expected) {
            throw new LogicException('Embedding provider returned a different batch size.');
        }
        foreach ($embeddings as $embedding) {
            if (!$embedding instanceof Embedding) {
                throw new LogicException('Embedding provider returned an invalid item.');
            }
        }
    }

    private function progress(int $scheduled, int $started, int $completed, int $discarded, int $chunksScheduled): BatchExecutionProgress
    {
        return new BatchExecutionProgress($scheduled, $started, $completed, $discarded, $chunksScheduled);
    }
}
