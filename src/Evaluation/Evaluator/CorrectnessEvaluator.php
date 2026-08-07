<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\ExpectedClaim;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

/**
 * Mede compatibilidade com os claims configurados no golden.
 *
 * A nota não representa verdade factual universal. Sem expectedClaims ou
 * expectedAnswer, a métrica retorna null e aparece como não aplicável.
 */
final readonly class CorrectnessEvaluator implements AnswerEvaluator
{
    public function __construct(private float $passingScore = 0.8) {}

    /** Compara cada claim com suas alternativas textuais normalizadas. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore
    {
        $claims = $case->expectedClaims;
        if ($claims === [] && $case->expectedAnswer !== null) {
            $claims = [new ExpectedClaim('expected_answer', [$case->expectedAnswer])];
        }
        if ($claims === []) {
            return null;
        }
        $answer = TextComparison::normalize($execution->answer->content);
        $matched = [];
        foreach ($claims as $claim) {
            if (array_any($claim->alternatives, static fn (string $alternative): bool => str_contains($answer, TextComparison::normalize($alternative)))) {
                $matched[] = $claim->id;
            }
        }
        $score = count($matched) / count($claims);
        return new EvaluationScore('correctness', $score, $score >= $this->passingScore, ['matchedClaims' => $matched]);
    }
}
