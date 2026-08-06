<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Metrics\GenerationMetrics;
use Omegaalfa\ContextEngine\Evaluation\Metrics\RetrievalMetrics;
use Omegaalfa\ContextEngine\Rag\RagExecution;

final readonly class EvaluationResult
{
    /** @param array<string, EvaluationScore> $scores */
    public function __construct(
        public EvaluationCase $case,
        public bool $passed,
        public array $scores,
        public RetrievalMetrics $retrieval,
        public GenerationMetrics $generation,
        public float $durationMilliseconds,
        public ?RagExecution $execution = null,
        public ?string $error = null,
    ) {}
}
