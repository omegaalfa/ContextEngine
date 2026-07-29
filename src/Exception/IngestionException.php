<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Exception;

use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Ingestion\IngestionReport;
use Throwable;

final class IngestionException extends ContextEngineException
{
    public function __construct(public readonly IngestionReport $partialReport, public readonly string $documentId, public readonly EmbeddingSpace $space, public readonly int $failedBatchSequence, Throwable $previous)
    {
        parent::__construct("Ingestion failed for document {$documentId} at batch {$failedBatchSequence} after {$partialReport->chunksPersisted} persisted chunks: {$previous->getMessage()}", 0, $previous);
    }
}
