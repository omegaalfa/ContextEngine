<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Contract\AbstentionPolicy;

final readonly class HybridEvidencePolicy implements AbstentionPolicy
{
    /**
     * Rejects only an isolated vector hit for a named term that is absent from
     * the retrieved content. Conceptual queries and multi-hit results remain untouched.
     *
     * @param list<VectorSearchResult> $results
     * @return AbstentionDecision
     */
    public function evaluate(string $question, array $results): AbstentionDecision
    {
        if ($results === []) {
            return new AbstentionDecision([], reason: 'no_candidates', signals: [
                'candidateCount' => 0,
                'lexicallySupportedCandidates' => 0,
            ]);
        }
        $lexicalSupport = count(array_filter($results, self::hasLexicalSupport(...)));
        if (count($results) !== 1 || $lexicalSupport > 0) {
            return new AbstentionDecision($results, signals: [
                'candidateCount' => count($results),
                'lexicallySupportedCandidates' => $lexicalSupport,
            ]);
        }
        $namedTerms = self::namedTerms($question);
        if ($namedTerms === [] || array_any($namedTerms, static fn (string $term): bool => self::contains($results[0]->chunk->content, $term))) {
            return new AbstentionDecision($results, signals: [
                'candidateCount' => 1,
                'lexicallySupportedCandidates' => 0,
                'namedTerms' => implode(', ', $namedTerms),
                'bestVectorDistance' => $results[0]->distance,
                'bestFusionScore' => $results[0]->fusionScore,
                'bestRerankerScore' => $results[0]->rerankerScore,
            ]);
        }
        return new AbstentionDecision([], [$results[0]->chunk->id], 'isolated_vector_hit_without_named_term', [
            'candidateCount' => 1,
            'lexicallySupportedCandidates' => 0,
            'namedTerms' => implode(', ', $namedTerms),
            'bestVectorDistance' => $results[0]->distance,
            'bestFusionScore' => $results[0]->fusionScore,
            'bestRerankerScore' => $results[0]->rerankerScore,
        ]);
    }

    /**
     * @param list<VectorSearchResult> $results
     * @return array{selected:list<VectorSearchResult>, discarded:list<string>}
     */
    public function select(string $question, array $results): array
    {
        $decision = $this->evaluate($question, $results);
        return ['selected' => $decision->selected, 'discarded' => $decision->discardedChunkIds];
    }

    private static function hasLexicalSupport(VectorSearchResult $result): bool
    {
        return $result->lexicalScore !== null
            || array_any($result->matches, static fn (QueryMatch $match): bool => $match->query === '__lexical__');
    }

    /** @return list<string> */
    private static function namedTerms(string $question): array
    {
        preg_match_all('/\b[\p{Lu}][\pL\pN]*(?:[-_][\pL\pN]+)*\b/u', $question, $matches);
        $ignored = ['como', 'qual', 'explique', 'para', 'que', 'uma', 'um', 'o', 'a'];
        return array_values(array_filter(array_unique($matches[0]), static fn (string $term): bool => !in_array(mb_strtolower($term), $ignored, true)));
    }

    private static function contains(string $content, string $term): bool
    {
        return mb_stripos($content, $term) !== false;
    }
}
