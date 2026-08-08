<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

use InvalidArgumentException;

final readonly class TokenLimitStrategy implements ChunkingStrategy
{
    public function __construct(public int $limit, private TokenEstimator $estimator = new HeuristicTokenEstimator())
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Token limit must be greater than zero.');
        }
    }

    public function fingerprint(): string
    {
        return 'tokens:' . $this->limit . ':' . $this->estimator->fingerprint();
    }

    public function fits(string $content, int $blockCount): bool
    {
        return $this->estimator->estimate($content) <= $this->limit;
    }

    public function split(string $content): array
    {
        $charactersPerToken = $this->estimator instanceof HeuristicTokenEstimator
            ? $this->estimator->charactersPerToken
            : 4;
        return new CharacterLimitStrategy($this->limit * $charactersPerToken)->split($content);
    }
}
