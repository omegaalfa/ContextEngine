<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;

interface TextSplitter
{
    /** Stable identity of every setting that changes the produced chunks. */
    public function fingerprint(): string;

    /**
     * @param Document $document
     * @return iterable<Chunk>
     */
    public function split(Document $document): iterable;
}
