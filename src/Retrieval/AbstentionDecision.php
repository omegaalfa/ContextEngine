<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

final readonly class AbstentionDecision
{
    /**
     * @param list<VectorSearchResult> $selected
     * @param list<string> $discardedChunkIds
     * @param array<string, scalar|null> $signals
     */
    public function __construct(
        public array $selected,
        public array $discardedChunkIds = [],
        public string $reason = 'evidence_accepted',
        public array $signals = [],
    ) {}

    public function abstained(): bool
    {
        return $this->selected === [];
    }
}
