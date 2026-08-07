<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

/**
 * Verifica literalmente se os grupos de termos configurados aparecem.
 *
 * É uma métrica simples para diagnóstico e regressão; por padrão, não decide
 * sozinha se uma resposta factual está correta.
 */
final readonly class ExpectedTermsEvaluator implements AnswerEvaluator
{
    /** Retorna null quando nenhum termo ou grupo foi configurado. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore
    {
        $groups = $case->expectedTermGroups;
        foreach ($case->expectedTerms as $term) {
            $groups[] = [$term];
        }
        if ($groups === []) {
            return null;
        }
        $answer = TextComparison::normalize($execution->answer->content);
        $matched = count(array_filter($groups, static fn (array $group): bool => array_any(
            $group,
            static fn (string $term): bool => str_contains($answer, TextComparison::normalize($term)),
        )));
        $score = $matched / count($groups);
        return new EvaluationScore('contains_expected_terms', $score, $score >= 1.0);
    }
}
