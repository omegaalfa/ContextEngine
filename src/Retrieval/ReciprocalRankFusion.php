<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class ReciprocalRankFusion
{
    public function __construct(private int $rankConstant = 60)
    {
        if ($rankConstant < 1) {
            throw new InvalidArgumentException('RRF rank constant must be positive.');
        }
    }
    /**
     * @param array<string, list<VectorSearchResult>> $rankings
     * @return list<VectorSearchResult>
     */
    public function fuse(array $rankings, int $limit): array
    {
        $aggregate = [];
        foreach ($rankings as $query => $results) {
            foreach ($results as $offset => $result) {
                $id = $result->chunk->id;
                $aggregate[$id] ??= [
                    'result' => $result,
                    'score' => 0.0,
                    'distance' => $result->distance,
                    'matches' => [],
                ];
                $rank = $offset + 1;
                $aggregate[$id]['score'] += 1 / ($this->rankConstant + $rank);
                $aggregate[$id]['distance'] = min($aggregate[$id]['distance'], $result->distance);
                $aggregate[$id]['matches'][] = new QueryMatch($query, $rank, $result->distance);
            }
        }
        uasort(
            $aggregate,
            static fn (array $left, array $right): int =>
            $right['score'] <=> $left['score']
            ?: $left['distance'] <=> $right['distance']
            ?: $left['result']->chunk->id <=> $right['result']->chunk->id
        );
        $fused = [];
        foreach (array_slice($aggregate, 0, $limit, true) as $item) {
            $result = $item['result'];
            $fused[] = new VectorSearchResult(
                $result->chunk,
                $item['distance'],
                $result->documentVersion,
                false,
                $item['score'],
                $item['matches'],
            );
        }
        return $fused;
    }
}
