<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

final readonly class AnswerRelevanceEvaluator implements CaseEvaluator
{
    /** @return list<EvaluationScore> */
    public function evaluate(EvaluationCase $case, RagExecution $execution): array
    {
        if ($case->expectedTerms === []) {
            return [];
        }
        $answer = TextComparison::normalize($execution->answer->content);
        $matched = count(array_filter(
            $case->expectedTerms,
            static fn (string $term): bool => str_contains($answer, TextComparison::normalize($term)),
        ));
        $score = $matched / count($case->expectedTerms);

        return [new EvaluationScore('contains_expected_terms', $score, $score >= 1.0)];
    }
}
