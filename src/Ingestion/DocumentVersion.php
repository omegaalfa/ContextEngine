<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

final readonly class DocumentVersion
{
    public string $id;

    public function __construct(public Document $document, public EmbeddingSpace $space, public string $chunkingFingerprint)
    {
        if (trim($chunkingFingerprint) === '') {
            throw new InvalidArgumentException('Chunking fingerprint cannot be empty.');
        }
        $metadata = $document->metadata;
        ksort($metadata);
        $this->id = hash('sha256', implode("\0", [
            $document->tenantId,
            $document->collection,
            $document->id,
            $document->status,
            $document->content,
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $space->fingerprint(),
            $chunkingFingerprint,
        ]));
    }
}
