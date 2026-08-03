<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\NeighborAwareVectorStore;
use Omegaalfa\ContextEngine\Contract\QueryRewriter;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Rag\Question;

final readonly class Retriever
{
    private QueryRewriter $queryRewriter;
    private NeighborExpansion $neighborExpansion;
    private ReciprocalRankFusion $fusion;
    public function __construct(
        private EmbeddingProvider $embeddings,
        private VectorStore $store,
        private RetrievalPolicy $policy = new RetrievalPolicy(),
        private ?string $collection = null,
        private string $status = 'active',
        ?QueryRewriter $queryRewriter = null,
        ?NeighborExpansion $neighborExpansion = null,
        private ?int $fusedLimit = null,
        private ?int $contextChunkLimit = null,
        private ?int $maximumContextCharacters = null,
        ?ReciprocalRankFusion $fusion = null,
    ) {
        $this->queryRewriter = $queryRewriter ?? new IdentityQueryRewriter();
        $this->neighborExpansion = $neighborExpansion ?? new NeighborExpansion();
        $this->fusion = $fusion ?? new ReciprocalRankFusion();
    }
    /** @return list<VectorSearchResult> */
    public function retrieve(Question $question): array
    {
        return $this->retrieveWithDiagnostics($question)->results;
    }
    public function retrieveWithDiagnostics(Question $question): RetrievalOutcome
    {
        $totalStarted = hrtime(true);
        $planningStarted = hrtime(true);
        $plan = $this->queryRewriter->rewrite($question);
        $planning = self::elapsed($planningStarted);
        $retrievalStarted = hrtime(true);
        $rankings = [];
        $hits = [];
        $resultsByQuery = [];
        foreach ($plan->queries as $query) {
            $embedding = $this->embeddings->embed($query, $question->tenantId);
            $rankings[$query] = $this->store->search(new VectorSearchQuery(
                $question->tenantId,
                $embedding,
                $this->policy,
                $this->collection,
                $this->status,
            ));
            $hits[$query] = count($rankings[$query]);
            $resultsByQuery[$query] = array_map(
                static fn (VectorSearchResult $result, int $offset): QueryResultDiagnostic => new QueryResultDiagnostic(
                    $query,
                    $offset + 1,
                    $result->chunk->id,
                    $result->chunk->documentId,
                    $result->chunk->position,
                    $result->distance,
                ),
                $rankings[$query],
                array_keys($rankings[$query]),
            );
        }
        $retrieval = self::elapsed($retrievalStarted);
        $fusionStarted = hrtime(true);
        $rawCount = array_sum($hits);
        $uniqueChunkIds = [];
        foreach ($rankings as $results) {
            foreach ($results as $result) {
                $uniqueChunkIds[$result->chunk->id] = true;
            }
        }
        $fused = $this->fusion->fuse($rankings, $this->fusedLimit ?? $this->policy->limit);
        $fusionTime = self::elapsed($fusionStarted);
        $expansionStarted = hrtime(true);
        [$expanded, $neighborIds] = $this->expand($question, $fused);
        $expansion = self::elapsed($expansionStarted);
        $selectionStarted = hrtime(true);
        $selection = new ContextSelector(
            $this->contextChunkLimit ?? $this->policy->limit,
            $this->maximumContextCharacters,
        )->select($expanded);
        $selectionTime = self::elapsed($selectionStarted);
        $diagnostics = new RetrievalDiagnostics(
            $plan->original,
            $plan->queries,
            $hits,
            $resultsByQuery,
            null,
            $rawCount - count($uniqueChunkIds),
            array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $fused),
            $neighborIds,
            array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $selection['selected']),
            $selection['discarded'],
            $selection['characters'],
            [
                'queryPlanning' => $planning,
                'retrieval' => $retrieval,
                'fusion' => $fusionTime,
                'expansion' => $expansion,
                'selection' => $selectionTime,
                'total' => self::elapsed($totalStarted),
            ],
        );
        return new RetrievalOutcome($selection['selected'], $diagnostics);
    }
    /**
     * @param list<VectorSearchResult> $hits
     * @return array{list<VectorSearchResult>, list<string>}
     */
    private function expand(Question $question, array $hits): array
    {
        if (!$this->neighborExpansion->enabled() || !$this->store instanceof NeighborAwareVectorStore) {
            return [$hits, []];
        }
        $rankedIds = array_fill_keys(array_map(static fn (VectorSearchResult $hit): string => $hit->chunk->id, $hits), true);
        $seen = [];
        $expanded = [];
        $neighborIds = [];
        foreach ($hits as $hit) {
            $group = [$hit];
            if ($hit->documentVersion !== null) {
                $neighbors = $this->store->neighbors(new NeighborSearchQuery(
                    $question->tenantId,
                    $hit->chunk->collection,
                    $hit->chunk->status,
                    $hit->chunk->documentId,
                    $hit->documentVersion,
                    $this->embeddings->space(),
                    $hit->chunk->position,
                    $this->neighborExpansion->before,
                    $this->neighborExpansion->after,
                ));
                foreach ($neighbors as $neighbor) {
                    if ($neighbor->id === $hit->chunk->id || isset($rankedIds[$neighbor->id])) {
                        continue;
                    }
                    $group[] = new VectorSearchResult(
                        $neighbor,
                        $hit->distance,
                        $hit->documentVersion,
                        true,
                        $hit->fusionScore,
                        $hit->matches,
                    );
                    $neighborIds[] = $neighbor->id;
                }
                usort(
                    $group,
                    static fn (VectorSearchResult $a, VectorSearchResult $b): int =>
                    $a->chunk->position <=> $b->chunk->position
                );
            }
            foreach ($group as $candidate) {
                if (!isset($seen[$candidate->chunk->id])) {
                    $seen[$candidate->chunk->id] = true;
                    $expanded[] = $candidate;
                }
            }
        }
        return [$expanded, array_values(array_unique($neighborIds))];
    }
    private static function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
