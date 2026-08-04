<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use Generator;
use InvalidArgumentException;
use LogicException;
use Omegaalfa\ContextEngine\Contract\BatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\TextSplitter;
use Omegaalfa\ContextEngine\Contract\VersionedVectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Support\Batcher;
use RuntimeException;
use Throwable;

final readonly class IngestionPipeline
{
    /**
     * @param TextSplitter $splitter
     * @param EmbeddingProvider $embeddings
     * @param VersionedVectorStore $store
     * @param BatchEmbeddingExecutor $executor
     * @param int $batchSize
     * @param Batcher $batcher
     */
    public function __construct(
        private TextSplitter           $splitter,
        private EmbeddingProvider      $embeddings,
        private VersionedVectorStore   $store,
        private BatchEmbeddingExecutor $executor,
        private int                    $batchSize = 32,
        private Batcher                $batcher = new Batcher(),
        private ?VersionValidator      $validator = null,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be greater than zero.');
        }
    }

    /**
     * @param DocumentLoader $loader
     * @return IngestionReport
     */
    public function ingest(DocumentLoader $loader): IngestionReport
    {
        $scheduled = 0;
        $started = 0;
        $completed = 0;
        $discarded = 0;
        $chunksScheduled = 0;
        $persistedBatches = 0;
        $persistedChunks = 0;
        $documentPersistedBatches = 0;
        $documentPersistedChunks = 0;
        $currentProgress = BatchExecutionProgress::empty();
        $currentDocument = '';
        $currentSequence = 0;
        $documentsActivated = 0;
        $currentVersion = null;

        try {
            foreach ($loader->load() as $document) {
                $currentDocument = $document->id;
                $currentVersion = new DocumentVersion($document, $this->embeddings->space(), $this->splitter->fingerprint());
                $validator = $this->validator ?? new VersionValidator();
                $validator->validate($currentVersion, []);
                $this->store->beginVersion($currentVersion);
                $currentProgress = BatchExecutionProgress::empty();
                $documentPersistedBatches = 0;
                $documentPersistedChunks = 0;
                $batches = $this->batcher->batches($this->splitter->split($document), $this->batchSize);
                $results = (function () use ($batches): iterable {
                    yield from $this->executor->execute($batches, $this->embeddings);
                })();
                $results->rewind();

                while ($results->valid()) {
                    $result = $results->current();
                    $currentProgress = $result->progress;
                    $currentSequence = $result->sequence;
                    foreach ($result->embeddings as $embedding) {
                        if ($embedding->space->fingerprint() !== $this->embeddings->space()->fingerprint()) {
                            throw new LogicException('Embedding provider returned an incompatible vector space.');
                        }
                    }

                    $embedded = [];
                    foreach ($result->chunks as $index => $chunk) {
                        $embedded[] = new EmbeddedChunk($chunk, $result->embeddings[$index]);
                    }

                    try {
                        $this->store->stageBatch($currentVersion, $embedded);
                    } catch (Throwable $persistenceError) {
                        $this->failVersion($currentVersion);
                        [$currentProgress, $affected] = $this->drainAfterPersistenceFailure(
                            $results,
                            $currentProgress,
                            [$currentSequence],
                            $documentPersistedBatches,
                        );
                        $failure = new IngestionFailure(
                            'batch_persistence_failed',
                            'Batch persistence failed.',
                            $currentDocument,
                            $currentSequence,
                        );
                        $report = $this->partialReport(
                            $scheduled,
                            $started,
                            $completed,
                            $discarded,
                            $chunksScheduled,
                            $currentProgress,
                            $persistedBatches,
                            $persistedChunks,
                            $documentsActivated,
                            $failure,
                            $affected,
                        );
                        throw new IngestionException($report, $currentDocument, $this->embeddings->space(), $currentSequence, $persistenceError);
                    }
                    $persistedBatches++;
                    $documentPersistedBatches++;
                    $persistedChunks += count($embedded);
                    $documentPersistedChunks += count($embedded);
                    $results->next();
                }

                $scheduled += $currentProgress->scheduled;
                $started += $currentProgress->started;
                $completed += $currentProgress->completed;
                $discarded += $currentProgress->discarded;
                $chunksScheduled += $currentProgress->chunksScheduled;
                if ($documentPersistedChunks === 0) {
                    throw new LogicException('A document version cannot be activated without chunks.');
                }
                $validator->validate($currentVersion, []);
                $this->store->activateVersion($currentVersion);
                $documentsActivated++;
                $currentVersion = null;
            }
        } catch (BatchWindowException $error) {
            $this->failVersion($currentVersion);
            $currentProgress = $error->progress;
            $failure = new IngestionFailure(
                'embedding_batch_failed',
                'Embedding generation failed.',
                $currentDocument,
                $error->failedSequence,
            );
            $report = $this->partialReport(
                $scheduled,
                $started,
                $completed,
                $discarded,
                $chunksScheduled,
                $currentProgress,
                $persistedBatches,
                $persistedChunks,
                $documentsActivated,
                $failure,
                array_values(array_unique([$error->failedSequence, ...$error->discarded])),
            );
            throw new IngestionException($report, $currentDocument, $this->embeddings->space(), $error->failedSequence, $error->getPrevious() ?? $error);
        } catch (IngestionException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->failVersion($currentVersion);
            $failure = new IngestionFailure('ingestion_failed', 'Ingestion processing failed.', $currentDocument !== '' ? $currentDocument : null, $currentSequence);
            $report = $this->partialReport(
                $scheduled,
                $started,
                $completed,
                $discarded,
                $chunksScheduled,
                $currentProgress,
                $persistedBatches,
                $persistedChunks,
                $documentsActivated,
                $failure,
                [$currentSequence],
            );
            throw new IngestionException($report, $currentDocument, $this->embeddings->space(), $currentSequence, $error);
        }

        return new IngestionReport(
            $scheduled,
            $started,
            $completed,
            $persistedBatches,
            $discarded,
            $chunksScheduled,
            $chunksScheduled,
            $persistedChunks,
            null,
            [],
            true,
            $documentsActivated,
            0,
        );
    }

    /**
     * @param Generator<mixed, BatchEmbeddingResult> $results
     * @param non-empty-list<int> $affected
     * @return array{BatchExecutionProgress, list<int>}
     */
    private function drainAfterPersistenceFailure(Generator $results, BatchExecutionProgress $progress, array $affected, int $documentPersistedBatches): array
    {
        $abort = new RuntimeException('Stop batch execution after persistence failure.');
        try {
            $results->throw($abort);
        } catch (Throwable) {
            // Persistence remains the primary failure after the window is drained.
        }

        $discarded = $progress->scheduled - $documentPersistedBatches;
        $progress = new BatchExecutionProgress(
            $progress->scheduled,
            $progress->started,
            $progress->started,
            $discarded,
            $progress->chunksScheduled,
        );
        $affected = [...$affected, ...range($affected[0], $progress->scheduled - 1)];

        return [$progress, array_values(array_unique($affected))];
    }

    /** @param list<int> $affected */
    private function partialReport(
        int                    $scheduled,
        int                    $started,
        int                    $completed,
        int                    $discarded,
        int                    $chunksScheduled,
        BatchExecutionProgress $current,
        int                    $persistedBatches,
        int                    $persistedChunks,
        int                    $documentsActivated,
        IngestionFailure       $failure,
        array                  $affected,
    ): IngestionReport {
        return new IngestionReport(
            $scheduled + $current->scheduled,
            $started + $current->started,
            $completed + $current->completed,
            $persistedBatches,
            $discarded + $current->discarded,
            $chunksScheduled + $current->chunksScheduled,
            $chunksScheduled + $current->chunksScheduled,
            $persistedChunks,
            $failure,
            $affected,
            false,
            $documentsActivated,
            1,
        );
    }

    /**
     * @param DocumentVersion|null $version
     * @return void
     */
    private function failVersion(?DocumentVersion $version): void
    {
        if ($version === null) {
            return;
        }
        try {
            $this->store->failVersion($version);
        } catch (Throwable) {
            // The original ingestion failure remains primary and the staged version stays invisible.
        }
    }
}
