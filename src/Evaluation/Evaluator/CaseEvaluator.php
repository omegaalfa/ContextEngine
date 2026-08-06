<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Rag\RagExecution;

interface CaseEvaluator
{
    /** @return list<EvaluationScore> */
    public function evaluate(EvaluationCase $case, RagExecution $execution): array;
}
