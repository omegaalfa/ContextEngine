<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Contract\QueryRewriter;
use Omegaalfa\ContextEngine\Rag\Question;

final readonly class IdentityQueryRewriter implements QueryRewriter
{
    public function rewrite(Question $question): RewrittenQueries
    {
        return new RewrittenQueries($question->content, [$question->content]);
    }
}
