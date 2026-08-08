<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Contract\IdentifiedReranker;
use Omegaalfa\ContextEngine\Rag\Question;

/**
 * Reranker offline de referência baseado na cobertura dos termos da pergunta.
 *
 * Ele é útil para testes, ablações e ambientes sem cross-encoder. Não substitui
 * um modelo semântico treinado para relevância.
 */
final readonly class DeterministicTextualReranker implements IdentifiedReranker
{
    public function name(): string
    {
        return 'DeterministicTextualReranker';
    }

    public function provider(): ?string
    {
        return null;
    }

    public function model(): ?string
    {
        return null;
    }

    public function rerank(Question $question, array $results): array
    {
        $terms = self::terms($question->content);
        $ranked = [];
        foreach ($results as $offset => $result) {
            $content = self::normalize($result->chunk->content);
            $matched = count(array_filter($terms, static fn (string $term): bool => str_contains($content, $term)));
            $score = $terms === [] ? 0.0 : $matched / count($terms);
            $ranked[] = [
                'offset' => $offset,
                'result' => new VectorSearchResult(
                    chunk: $result->chunk,
                    distance: $result->distance,
                    documentVersion: $result->documentVersion,
                    neighbor: $result->neighbor,
                    fusionScore: $result->fusionScore,
                    matches: $result->matches,
                    provenance: $result->provenance,
                    lexicalScore: $result->lexicalScore,
                    rerankerScore: $score,
                ),
            ];
        }
        usort(
            $ranked,
            static fn (array $left, array $right): int =>
            $right['result']->rerankerScore <=> $left['result']->rerankerScore
            ?: $left['offset'] <=> $right['offset']
        );
        return array_map(static fn (array $item): VectorSearchResult => $item['result'], $ranked);
    }

    /** @return list<string> */
    private static function terms(string $text): array
    {
        $ignored = ['a', 'ao', 'as', 'como', 'da', 'de', 'do', 'e', 'em', 'é', 'o', 'os', 'para', 'por', 'qual', 'que', 'um', 'uma'];
        $tokens = preg_split('/\s+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $term): bool => mb_strlen($term) >= 2 && !in_array($term, $ignored, true),
        )));
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
