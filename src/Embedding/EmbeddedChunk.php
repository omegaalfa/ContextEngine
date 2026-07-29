<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Embedding;

use Omegaalfa\ContextEngine\Chunk\Chunk;

final readonly class EmbeddedChunk
{
    public function __construct(public Chunk $chunk, public Embedding $embedding) {}
}
