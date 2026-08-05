<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

final readonly class HeuristicTokenEstimator implements TokenEstimator
{
    public function estimate(string $content): int
    {
        return max(1, (int) ceil(mb_strlen($content) / 4));
    }

    public function fingerprint(): string
    {
        return 'heuristic-utf8-characters-v1';
    }
}
