<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

final readonly class RetrievalDiagnostics
{
    /**
     * @param non-empty-list<string> $queries
     * @param array<string, int> $hitsPerQuery
     * @param array<string, list<QueryResultDiagnostic>> $resultsByQuery
     * @param list<string> $fusedChunkIds
     * @param list<string> $neighborChunkIds
     * @param list<string> $selectedChunkIds
     * @param list<string> $discardedByBudgetChunkIds
     * @param array<string, float> $timingsMilliseconds
     * @param list<ContextSelectionDiagnostic> $contextSelection
     * @param list<string> $expandedChunkIds
     * @param list<string> $relevanceSelectedChunkIds
     * @param list<string> $evidenceDiscardedChunkIds
     */
    public function __construct(
        public string $originalQuestion,
        public array $queries,
        public array $hitsPerQuery,
        public array $resultsByQuery,
        public ?int $removedByMaximumDistance,
        public int $deduplicatedResults,
        public array $fusedChunkIds,
        public array $neighborChunkIds,
        public array $selectedChunkIds,
        public array $discardedByBudgetChunkIds,
        public int $contextCharacters,
        public array $timingsMilliseconds,
        public array $contextSelection = [],
        public array $expandedChunkIds = [],
        public array $relevanceSelectedChunkIds = [],
        public array $evidenceDiscardedChunkIds = [],
    ) {}
}
