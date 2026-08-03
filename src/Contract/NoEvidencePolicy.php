<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Rag\Question;

interface NoEvidencePolicy
{
    public function response(Question $question): string;
}
