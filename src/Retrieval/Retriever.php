<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Contract\AbstentionPolicy;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\IdentifiedReranker;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Contract\NeighborAwareVectorStore;
use Omegaalfa\ContextEngine\Contract\QueryRewriter;
use Omegaalfa\ContextEngine\Contract\Reranker;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Exception\RerankerException;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;

final readonly class Retriever
{
    /**
     *
     */
    private const LEXICAL_RANKING_KEY = '__lexical__';

    /**
     * @var QueryRewriter|IdentityQueryRewriter
     */
    private QueryRewriter $queryRewriter;
    /**
     * @var NeighborExpansion
     */
    private NeighborExpansion $neighborExpansion;
    /**
     * @var ReciprocalRankFusion
     */
    private ReciprocalRankFusion $fusion;

    /**
     * @param EmbeddingProvider $embeddings
     * @param VectorStore $store
     * @param RetrievalPolicy $policy
     * @param string|null $collection
     * @param string $status
     * @param QueryRewriter|null $queryRewriter
     * @param NeighborExpansion|null $neighborExpansion
     * @param int|null $fusedLimit
     * @param int|null $contextChunkLimit
     * @param int|null $maximumContextCharacters
     * @param ReciprocalRankFusion|null $fusion
     * @param ContextRelevancePolicy|null $contextRelevancePolicy
     * @param VersionSelectionPolicy|null $versionSelectionPolicy
     * @param LexicalSearchStore|null $lexicalStore
     * @param array<string, float> $rankingWeights
     * @param AbstentionPolicy|null $evidencePolicy
     * @param Reranker|null $reranker
     */
    public function __construct(
        private EmbeddingProvider       $embeddings,
        private VectorStore             $store,
        private RetrievalPolicy         $policy = new RetrievalPolicy(),
        private ?string                 $collection = null,
        private string                  $status = 'active',
        ?QueryRewriter                  $queryRewriter = null,
        ?NeighborExpansion              $neighborExpansion = null,
        private ?int                    $fusedLimit = null,
        private ?int                    $contextChunkLimit = null,
        private ?int                    $maximumContextCharacters = null,
        ?ReciprocalRankFusion           $fusion = null,
        private ?ContextRelevancePolicy $contextRelevancePolicy = null,
        private ?VersionSelectionPolicy $versionSelectionPolicy = null,
        private ?LexicalSearchStore     $lexicalStore = null,
        private array                   $rankingWeights = [],
        private ?AbstentionPolicy      $evidencePolicy = null,
        private ?Reranker              $reranker = null,
        private ?int                   $lexicalCandidateLimit = null,
        private ?int                   $rerankerCandidateLimit = null,
        private string                 $textSearchConfiguration = 'portuguese',
    ) {
        $this->queryRewriter = $queryRewriter ?? new IdentityQueryRewriter();
        $this->neighborExpansion = $neighborExpansion ?? new NeighborExpansion();
        $this->fusion = $fusion ?? new ReciprocalRankFusion();
        foreach ($rankingWeights as $source => $weight) {
            if (!in_array($source, ['vector', 'lexical'], true) || !is_finite($weight) || $weight < 0) {
                throw new \InvalidArgumentException('Ranking weights support finite non-negative vector and lexical values.');
            }
        }
        if ($lexicalCandidateLimit !== null && ($lexicalCandidateLimit < 1 || $lexicalCandidateLimit > 100)
            || $rerankerCandidateLimit !== null && $rerankerCandidateLimit < 1) {
            throw new \InvalidArgumentException('Candidate limits must be positive and lexical limit cannot exceed 100.');
        }
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $textSearchConfiguration) !== 1) {
            throw new \InvalidArgumentException('PostgreSQL text search configuration must be a safe identifier.');
        }
    }

    /** @return list<VectorSearchResult> */
    public function retrieve(Question $question): array
    {
        return $this->retrieveWithDiagnostics($question)->results;
    }

    /**
     * @param Question $question
     * @return RetrievalOutcome
     */
    public function retrieveWithDiagnostics(Question $question): RetrievalOutcome
    {
        $totalStarted = hrtime(true);
        $planningStarted = hrtime(true);
        $plan = $this->queryRewriter->rewrite($question);
        $diagnosticQueries = $plan->queries;
        $planning = self::elapsed($planningStarted);
        $retrievalStarted = hrtime(true);
        $rankings = [];
        $hits = [];
        $resultsByQuery = [];
        $lexicalRetrieval = null;
        foreach ($plan->queries as $query) {
            $embedding = $this->embeddings->embed($query, $question->tenantId);
            $rankings[$query] = $this->store->search(new VectorSearchQuery(
                $question->tenantId,
                $embedding,
                $this->policy,
                $this->collection,
                $this->status,
                $this->versionSelectionPolicy,
            ));
            $hits[$query] = count($rankings[$query]);
            $resultsByQuery[$query] = $this->toQueryDiagnostics($query, $rankings[$query]);
        }
        if ($this->lexicalStore !== null) {
            $lexicalStarted = hrtime(true);
            $lexicalQuery = new LexicalSearchQuery(
                tenantId: $question->tenantId,
                terms: $question->content,
                policy: new RetrievalPolicy(
                    $this->lexicalCandidateLimit ?? $this->policy->limit,
                    $this->policy->metric,
                    $this->policy->maximumDistance,
                ),
                collection: $this->collection,
                status: $this->status,
                versionSelectionPolicy: $this->versionSelectionPolicy,
                textSearchConfiguration: $this->textSearchConfiguration,
            );
            $rankings[self::LEXICAL_RANKING_KEY] = $this->lexicalStore->searchLexical($lexicalQuery);
            $hits[self::LEXICAL_RANKING_KEY] = count($rankings[self::LEXICAL_RANKING_KEY]);
            $resultsByQuery[self::LEXICAL_RANKING_KEY] = $this->toQueryDiagnostics(
                self::LEXICAL_RANKING_KEY,
                $rankings[self::LEXICAL_RANKING_KEY],
            );
            $diagnosticQueries[] = self::LEXICAL_RANKING_KEY;
            $lexicalRetrieval = self::elapsed($lexicalStarted);
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
        $weights = [];
        foreach (array_keys($rankings) as $ranking) {
            $weights[$ranking] = (float) ($this->rankingWeights[$ranking === self::LEXICAL_RANKING_KEY ? 'lexical' : 'vector'] ?? 1.0);
        }
        $fused = $this->fusion->fuse($rankings, $this->fusedLimit ?? $this->policy->limit, $weights);
        $fusionTime = self::elapsed($fusionStarted);
        $rerankingStarted = hrtime(true);
        $rerankerFailure = null;
        $rerankerCandidates = $this->reranker === null || $this->rerankerCandidateLimit === null
            ? $fused
            : array_slice($fused, 0, $this->rerankerCandidateLimit);
        try {
            $reranked = $this->reranker?->rerank($question, $rerankerCandidates) ?? $rerankerCandidates;
        } catch (RerankerException $exception) {
            $rerankerFailure = $exception;
            $reranked = $rerankerCandidates;
        }
        $this->validateReranked($rerankerCandidates, $reranked);
        $reranking = self::elapsed($rerankingStarted);
        $adaptive = $this->contextRelevancePolicy === null
            ? ['selected' => $reranked, 'decisions' => []]
            : new AdaptiveContextSelector($this->contextRelevancePolicy)->select($question->content, $reranked);
        $abstention = $this->evidencePolicy?->evaluate($question->content, $adaptive['selected'])
            ?? new AbstentionDecision($adaptive['selected']);
        $expansionStarted = hrtime(true);
        [$expanded, $neighborIds] = $this->expand($question, $abstention->selected);
        $expansion = self::elapsed($expansionStarted);
        $selectionStarted = hrtime(true);
        $selection = new ContextSelector(
            $this->contextChunkLimit ?? $this->policy->limit,
            $this->maximumContextCharacters,
        )->select($expanded);
        $selectionDecisions = $this->finalDecisions(
            $adaptive['decisions'],
            $selection['discardReasons'],
        );
        $selectionTime = self::elapsed($selectionStarted);
        $diagnostics = new RetrievalDiagnostics(
            $plan->original,
            $diagnosticQueries,
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
                ...($lexicalRetrieval !== null ? ['lexicalRetrieval' => $lexicalRetrieval] : []),
                'fusion' => $fusionTime,
                'reranking' => $reranking,
                'expansion' => $expansion,
                'selection' => $selectionTime,
                'total' => self::elapsed($totalStarted),
            ],
            $selectionDecisions,
            array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $expanded),
            array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $adaptive['selected']),
            $abstention->discardedChunkIds,
            array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $reranked),
            $this->rerankDiagnostics($fused, $reranked),
            $this->reranker instanceof IdentifiedReranker ? $this->reranker->name() : ($this->reranker === null ? null : $this->reranker::class),
            count($rerankerCandidates),
            count($reranked),
            $this->reranker instanceof IdentifiedReranker ? $this->reranker->provider() : null,
            $this->reranker instanceof IdentifiedReranker ? $this->reranker->model() : null,
            $rerankerFailure === null ? 0 : 1,
            $rerankerFailure === null ? 0 : 1,
            $rerankerFailure === null ? false : $rerankerFailure->timedOut,
            $rerankerFailure === null ? null : $rerankerFailure->getMessage(),
            $abstention->abstained(),
            $abstention->reason,
            $abstention->signals,
        );
        return new RetrievalOutcome($selection['selected'], $diagnostics);
    }

    /**
     * @param list<VectorSearchResult> $before
     * @param list<VectorSearchResult> $after
     */
    private function validateReranked(array $before, array $after): void
    {
        $beforeIds = array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $before);
        $afterIds = array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $after);
        sort($beforeIds);
        sort($afterIds);
        if ($beforeIds !== $afterIds) {
            throw new \InvalidArgumentException('Reranker must preserve every candidate exactly once.');
        }
    }

    /**
     * @param list<VectorSearchResult> $before
     * @param list<VectorSearchResult> $after
     * @return list<RerankDiagnostic>
     */
    private function rerankDiagnostics(array $before, array $after): array
    {
        $beforeRanks = [];
        foreach ($before as $offset => $result) {
            $beforeRanks[$result->chunk->id] = $offset + 1;
        }
        return array_map(
            static fn (VectorSearchResult $result, int $offset): RerankDiagnostic => new RerankDiagnostic(
                $result->chunk->id,
                $beforeRanks[$result->chunk->id],
                $offset + 1,
                $result->distance,
                $result->lexicalScore,
                $result->fusionScore,
                $result->rerankerScore,
            ),
            $after,
            array_keys($after),
        );
    }

    /**
     * @param list<VectorSearchResult> $results
     * @return list<QueryResultDiagnostic>
     */
    private function toQueryDiagnostics(string $query, array $results): array
    {
        return array_map(
            static fn (VectorSearchResult $result, int $offset): QueryResultDiagnostic => new QueryResultDiagnostic(
                $query,
                $offset + 1,
                $result->chunk->id,
                $result->chunk->documentId,
                $result->chunk->position,
                $result->distance,
                $result->lexicalScore,
            ),
            $results,
            array_keys($results),
        );
    }

    /**
     * @param list<ContextSelectionDiagnostic> $adaptive
     * @param array<string, ContextSelectionReason> $budgetDiscardReasons
     * @return list<ContextSelectionDiagnostic>
     */
    private function finalDecisions(array $adaptive, array $budgetDiscardReasons): array
    {
        if ($adaptive === []) {
            return [];
        }
        return array_map(
            static fn (ContextSelectionDiagnostic $decision): ContextSelectionDiagnostic => isset($budgetDiscardReasons[$decision->chunkId])
                ? new ContextSelectionDiagnostic(
                    $decision->chunkId,
                    false,
                    $budgetDiscardReasons[$decision->chunkId],
                )
                : $decision,
            $adaptive,
        );
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
                        $hit->provenance,
                        $hit->lexicalScore,
                        $hit->rerankerScore,
                    );
                    $neighborIds[] = $neighbor->id;
                }
                usort(
                    $group,
                    static fn (VectorSearchResult $a, VectorSearchResult $b): int => $a->chunk->position <=> $b->chunk->position
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

    /**
     * @param int $started
     * @return float
     */
    private static function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
