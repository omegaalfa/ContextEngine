<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Prompt\ChatMessage;

interface LanguageModel
{
    /** @param list<ChatMessage> $messages */
    public function complete(array $messages): string;
}
