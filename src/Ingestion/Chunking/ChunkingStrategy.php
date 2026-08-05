<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

interface ChunkingStrategy
{
    public function fingerprint(): string;

    public function fits(string $content, int $blockCount): bool;

    /** @return list<string> */
    public function split(string $content): array;
}
