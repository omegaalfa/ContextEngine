<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;

interface VersionedVectorStore extends VectorStore
{
    /** Removes remnants of a previous failed attempt without touching an active version. */
    public function beginVersion(DocumentVersion $version): void;

    /** @param list<EmbeddedChunk> $chunks */
    public function stageBatch(DocumentVersion $version, array $chunks): void;

    /** Atomically supersedes the previous active version and activates this one. */
    public function activateVersion(DocumentVersion $version): void;

    /** Marks staged rows as failed; an active version is never changed. */
    public function failVersion(DocumentVersion $version): void;
}
