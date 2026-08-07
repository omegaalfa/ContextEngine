<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Rag\RagExecution;

/**
 * Nome compatível para o avaliador de apoio textual determinístico.
 *
 * Código novo deve preferir DeterministicTextualGroundednessEvaluator, cujo nome
 * deixa explícito que a análise não compreende semântica profunda.
 */
final readonly class DeterministicGroundednessEvaluator implements AnswerEvaluator
{
    public function __construct(private float $minimumCoverage = 0.6, private float $passingScore = 0.8) {}

    /** Delega a avaliação ao avaliador textual atual. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore
    {
        return new DeterministicTextualGroundednessEvaluator(
            $this->minimumCoverage,
            $this->passingScore,
        )->evaluate($case, $execution);
    }
}
