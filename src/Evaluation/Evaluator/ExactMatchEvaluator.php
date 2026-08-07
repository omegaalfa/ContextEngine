<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

final readonly class ExactMatchEvaluator implements CaseEvaluator
{
    /** @return list<EvaluationScore> */
    public function evaluate(EvaluationCase $case, RagExecution $execution): array
    {
        if ($case->expectedAnswer === null) {
            return [];
        }
        $strict = $execution->answer->content === $case->expectedAnswer;
        $normalized = TextComparison::normalize($execution->answer->content)
            === TextComparison::normalize($case->expectedAnswer);

        return [
            new EvaluationScore('strict_exact_match', $strict ? 1.0 : 0.0, $strict),
            new EvaluationScore('normalized_exact_match', $normalized ? 1.0 : 0.0, $normalized),
        ];
    }
}
