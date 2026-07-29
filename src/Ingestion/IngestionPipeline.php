<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use Omegaalfa\ContextEngine\Contract\{BatchEmbeddingExecutor,DocumentLoader};
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\TextSplitter;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Support\Batcher;

final readonly class IngestionPipeline
{
    public function __construct(private TextSplitter $splitter, private EmbeddingProvider $embeddings, private VectorStore $store, private int $batchSize = 32, private Batcher $batcher = new Batcher(), private BatchEmbeddingExecutor $executor = new FiberBatchEmbeddingExecutor()) {}
    public function ingest(DocumentLoader $loader): IngestionReport
    {
        $persistedBatches = 0;
        $persistedChunks = 0;
        $completedBatches = 0;
        $chunksProduced = 0;
        $batchesPlanned = 0;
        $currentDocument = '';
        try {
            foreach ($loader->load() as $document) {
                $currentDocument = $document->id;
                $batches = $this->batcher->batches($this->splitter->split($document), $this->batchSize);
                foreach ($this->executor->execute($batches, $this->embeddings) as $result) {
                    $batchesPlanned++;
                    $completedBatches++;
                    $chunksProduced += count($result->chunks);
                    foreach ($result->embeddings as $embedding) {
                        if ($embedding->space->fingerprint() !== $this->embeddings->space()->fingerprint()) {
                            throw new \LogicException('Embedding provider returned an incompatible vector space.');
                        }
                    }
                    $embedded = [];
                    foreach ($result->chunks as $index => $chunk) {
                        $embedded[] = new EmbeddedChunk($chunk, $result->embeddings[$index]);
                    }
                    $this->store->storeBatch($embedded);
                    $persistedBatches++;
                    $persistedChunks += count($embedded);
                }
            }
        } catch (BatchWindowException $e) {
            $started = count($e->started);
            $completed = count($e->completed);
            $sent = array_sum($e->chunkCounts);
            $produced = $chunksProduced + $sent;
            $report = new IngestionReport($batchesPlanned + $started, $batchesPlanned + $started, $completedBatches + $completed, $persistedBatches, count($e->discarded), $produced, $produced, $persistedChunks, $e->getPrevious()?->getMessage() ?? $e->getMessage(), array_values(array_unique([$e->failedSequence, ...$e->discarded])), false);
            throw new IngestionException($report, $currentDocument, $this->embeddings->space(), $e->failedSequence, $e->getPrevious() ?? $e);
        } catch (\Throwable $e) {
            $report = new IngestionReport($batchesPlanned, $batchesPlanned, $completedBatches, $persistedBatches, 0, $chunksProduced, $chunksProduced, $persistedChunks, $e->getMessage(), [], false);
            throw new IngestionException($report, $currentDocument, $this->embeddings->space(), max(0, $batchesPlanned - 1), $e);
        }
        return new IngestionReport($batchesPlanned, $batchesPlanned, $completedBatches, $persistedBatches, 0, $chunksProduced, $chunksProduced, $persistedChunks, null, [], true);
    }
}
