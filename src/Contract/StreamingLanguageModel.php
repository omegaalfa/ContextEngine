<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Rag\AnswerDelta;

interface StreamingLanguageModel
{
    /**
     * @param list<ChatMessage> $messages
     * @return iterable<AnswerDelta>
     */
    public function stream(array $messages): iterable;
}
