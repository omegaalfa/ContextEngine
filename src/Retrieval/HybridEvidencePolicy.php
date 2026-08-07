<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

final readonly class HybridEvidencePolicy
{
    /**
     * Rejects only an isolated vector hit for a named term that is absent from
     * the retrieved content. Conceptual queries and multi-hit results remain untouched.
     *
     * @param list<VectorSearchResult> $results
     * @return array{selected:list<VectorSearchResult>, discarded:list<string>}
     */
    public function select(string $question, array $results): array
    {
        if (count($results) !== 1 || self::hasLexicalSupport($results[0])) {
            return ['selected' => $results, 'discarded' => []];
        }
        $namedTerms = self::namedTerms($question);
        if ($namedTerms === [] || array_any($namedTerms, static fn (string $term): bool => self::contains($results[0]->chunk->content, $term))) {
            return ['selected' => $results, 'discarded' => []];
        }
        return ['selected' => [], 'discarded' => [$results[0]->chunk->id]];
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
