<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;

/**
 * Decide quais notas de resposta são suficientes para aprovar um caso.
 *
 * Resolve o problema de uma métrica diagnóstica, como termos esperados,
 * reprovar sozinha uma resposta que está correta e apoiada no contexto.
 */
final readonly class AnswerEvaluationPolicy
{
    public function __construct(
        public float $minimumGroundedness = 0.8,
        public float $minimumAnswerRelevance = 0.8,
        public float $minimumCorrectness = 0.8,
        public bool $requireExpectedTerms = false,
    ) {
        foreach ([$minimumGroundedness, $minimumAnswerRelevance, $minimumCorrectness] as $threshold) {
            if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
                throw new InvalidArgumentException('Answer evaluation thresholds must be between zero and one.');
            }
        }
    }

    /**
     * Verifica somente métricas presentes; uma métrica sem gabarito fica não aplicável.
     *
     * @param array<string, EvaluationScore> $scores
     */
    public function passes(array $scores): bool
    {
        $requirements = [
            'groundedness' => $this->minimumGroundedness,
            'answer_relevance' => $this->minimumAnswerRelevance,
            'correctness' => $this->minimumCorrectness,
        ];
        foreach ($requirements as $name => $minimum) {
            if (isset($scores[$name]) && $scores[$name]->value < $minimum) {
                return false;
            }
        }
        return !$this->requireExpectedTerms
            || !isset($scores['contains_expected_terms'])
            || $scores['contains_expected_terms']->passed;
    }
}
