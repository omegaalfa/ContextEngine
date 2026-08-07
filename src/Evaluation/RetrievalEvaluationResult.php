<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Retrieval\RetrievalOutcome;

final readonly class RetrievalEvaluationResult
{
    /** @param array<string, EvaluationScore> $scores */
    public function __construct(
        public EvaluationCase $case,
        public EvaluationStatus $status,
        public array $scores,
        public float $durationMilliseconds,
        public ?RetrievalOutcome $outcome = null,
        public ?string $error = null,
    ) {}
}
