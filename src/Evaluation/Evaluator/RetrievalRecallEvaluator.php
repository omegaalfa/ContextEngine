<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

final readonly class RetrievalRecallEvaluator implements CaseEvaluator
{
    /** @return list<EvaluationScore> */
    public function evaluate(EvaluationCase $case, RagExecution $execution): array
    {
        $scores = [];
        if ($case->hasChunkGroundTruth) {
            $scores = [...$scores, ...self::scores('chunk', $case->relevantChunkIds, $execution->diagnostics->retrieval->selectedChunkIds)];
        }
        if ($case->hasDocumentGroundTruth) {
            $documents = array_map(static fn ($source): string => $source->chunk->documentId, $execution->answer->sources);
            $scores = [...$scores, ...self::scores('document', $case->relevantDocumentIds, $documents)];
        }
        if ($case->relevantEvidence !== []) {
            $matched = 0;
            foreach ($case->relevantEvidence as $evidence) {
                if (array_any($execution->answer->sources, static function ($source) use ($evidence): bool {
                    if ($source->chunk->documentId !== $evidence->documentId) {
                        return false;
                    }
                    $content = TextComparison::normalize($source->chunk->content);
                    return array_all($evidence->requiredTextGroups, static fn (array $group): bool => array_any(
                        $group,
                        static fn (string $term): bool => str_contains($content, TextComparison::normalize($term)),
                    ));
                })) {
                    ++$matched;
                }
            }
            $value = $matched / count($case->relevantEvidence);
            $scores[] = new EvaluationScore('evidence_recall', $value, $value >= 1.0);
        }
        if ($case->expectNoEvidence) {
            $passed = $execution->diagnostics->retrieval->selectedChunkIds === [];
            $scores[] = new EvaluationScore('no_evidence', $passed ? 1.0 : 0.0, $passed);
        }
        return $scores;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $retrieved
     * @return list<EvaluationScore>
     */
    private static function scores(string $prefix, array $expected, array $retrieved): array
    {

        $expected = array_values(array_unique($expected));
        $retrieved = array_values(array_unique($retrieved));
        $hits = array_values(array_intersect($retrieved, $expected));
        $firstRank = null;
        foreach ($retrieved as $offset => $id) {
            if (in_array($id, $expected, true)) {
                $firstRank = $offset + 1;
                break;
            }
        }

        $recall = count($hits) / count($expected);
        $precision = $retrieved === [] ? 0.0 : count($hits) / count($retrieved);
        $hitRate = $hits === [] ? 0.0 : 1.0;
        $hitAtOne = $retrieved !== [] && in_array($retrieved[0], $expected, true) ? 1.0 : 0.0;
        $reciprocalRank = $firstRank === null ? 0.0 : 1 / $firstRank;

        return [
            new EvaluationScore($prefix.'_recall', $recall, $recall >= 1.0),
            new EvaluationScore($prefix.'_precision', $precision, $hits !== []),
            new EvaluationScore($prefix.'_mrr', $reciprocalRank, $hits !== []),
            new EvaluationScore($prefix.'_hit_rate', $hitRate, $hits !== []),
            new EvaluationScore($prefix.'_hit_at_1', $hitAtOne, $hitAtOne >= 1.0),
        ];
    }
}
