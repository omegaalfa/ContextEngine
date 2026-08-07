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
     * @param array<string, float> $weights
     * @return list<VectorSearchResult>
     */
    public function fuse(array $rankings, int $limit, array $weights = []): array
    {
        $aggregate = [];
        foreach ($rankings as $query => $results) {
            $weight = $weights[$query] ?? 1.0;
            if (!is_finite($weight) || $weight < 0) {
                throw new InvalidArgumentException('RRF ranking weights must be finite and non-negative.');
            }
            if ($weight === 0.0) {
                continue;
            }
            foreach ($results as $offset => $result) {
                $id = $result->chunk->id;
                $aggregate[$id] ??= [
                    'result' => $result,
                    'score' => 0.0,
                    'distance' => $result->distance,
                    'matches' => [],
                    'lexicalScore' => null,
                ];
                $rank = $offset + 1;
                $aggregate[$id]['score'] += $weight / ($this->rankConstant + $rank);
                $aggregate[$id]['distance'] = min($aggregate[$id]['distance'], $result->distance);
                $aggregate[$id]['matches'][] = new QueryMatch($query, $rank, $result->distance);
                if ($result->lexicalScore !== null) {
                    $aggregate[$id]['lexicalScore'] = max($aggregate[$id]['lexicalScore'] ?? 0.0, $result->lexicalScore);
                }
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
                $result->provenance,
                $item['lexicalScore'],
            );
        }
        return $fused;
    }
}
