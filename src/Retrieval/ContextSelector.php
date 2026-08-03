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
     * @return array{selected: list<VectorSearchResult>, discarded: list<string>, characters: int}
     */
    public function select(array $candidates): array
    {
        $selected = [];
        $discarded = [];
        $characters = 0;
        foreach ($candidates as $candidate) {
            $next = $characters + mb_strlen($candidate->chunk->content);
            if (count($selected) >= $this->chunkLimit
                || $this->maximumCharacters !== null && $next > $this->maximumCharacters) {
                $discarded[] = $candidate->chunk->id;
                continue;
            }
            $selected[] = $candidate;
            $characters = $next;
        }
        return ['selected' => $selected, 'discarded' => $discarded, 'characters' => $characters];
    }
}
