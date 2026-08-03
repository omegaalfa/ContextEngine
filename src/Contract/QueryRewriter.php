<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\RewrittenQueries;

interface QueryRewriter
{
    public function rewrite(Question $question): RewrittenQueries;
}
