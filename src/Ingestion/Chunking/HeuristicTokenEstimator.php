<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

final readonly class HeuristicTokenEstimator implements TokenEstimator
{
    public function __construct(public int $charactersPerToken = 4)
    {
        if ($charactersPerToken < 1) {
            throw new \InvalidArgumentException('Characters per token must be greater than zero.');
        }
    }

    public function estimate(string $content): int
    {
        return max(1, (int) ceil(mb_strlen($content) / $this->charactersPerToken));
    }

    public function fingerprint(): string
    {
        return 'heuristic-utf8-characters-v1:' . $this->charactersPerToken;
    }
}
