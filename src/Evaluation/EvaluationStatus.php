<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

enum EvaluationStatus: string
{
    case PASSED = 'passed';
    case FAILED = 'failed';
    case ERROR = 'error';
    case NOT_APPLICABLE = 'not_applicable';
}
