<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Prompt;

enum Role: string
{
    case SYSTEM = 'system';
    case USER = 'user';
    case ASSISTANT = 'assistant';
}
final readonly class ChatMessage
{
    public function __construct(public Role $role, public string $content) {}
}
