<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class ContextSelector
{
    public function __construct(
        private int $chunkLimit,
        private ?int $maximumCharacters = null,
    ) {
        if ($chunkLimit < 1 || $maximumCharacters !== null && $maximumCharacters < 1) {
            throw new InvalidArgumentException('Context limits must be positive.');
        }
    }
    /**
     * @param list<VectorSearchResult> $candidates
     * @return array{
     *     selected: list<VectorSearchResult>,
     *     discarded: list<string>,
     *     discardReasons: array<string, ContextSelectionReason>,
     *     characters: int
     * }
     */
    public function select(array $candidates): array
    {
        $selected = [];
        $discarded = [];
        $discardReasons = [];
        $characters = 0;
        foreach ($candidates as $candidate) {
            $next = $characters + mb_strlen($candidate->chunk->content);
            if (count($selected) >= $this->chunkLimit) {
                $discarded[] = $candidate->chunk->id;
                $discardReasons[$candidate->chunk->id] = ContextSelectionReason::SOURCE_LIMIT;
                continue;
            }
            if ($this->maximumCharacters !== null && $next > $this->maximumCharacters) {
                $discarded[] = $candidate->chunk->id;
                $discardReasons[$candidate->chunk->id] = ContextSelectionReason::CONTEXT_BUDGET;
                continue;
            }
            $selected[] = $candidate;
            $characters = $next;
        }
        return [
            'selected' => $selected,
            'discarded' => $discarded,
            'discardReasons' => $discardReasons,
            'characters' => $characters,
        ];
    }
}
