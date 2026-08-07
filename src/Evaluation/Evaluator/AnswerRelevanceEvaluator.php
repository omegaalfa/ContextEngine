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
        $groups = $case->expectedTermGroups;
        foreach ($case->expectedTerms as $term) {
            $groups[] = [$term];
        }
        if ($groups === []) {
            return [];
        }
        $answer = TextComparison::normalize($execution->answer->content);
        $matched = count(array_filter(
            $groups,
            static fn (array $group): bool => array_any(
                $group,
                static fn (string $term): bool => str_contains($answer, TextComparison::normalize($term)),
            ),
        ));
        $score = $matched / count($groups);

        return [new EvaluationScore('contains_expected_terms', $score, $score >= 1.0)];
    }
}
