<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use InvalidArgumentException;

final readonly class AnswerDelta
{
    public function __construct(public string $content, public int $sequence = 0, public bool $final = false)
    {
        if ($content === '' && !$final || $sequence < 0) {
            throw new InvalidArgumentException('A delta needs content or a final marker and a non-negative sequence.');
        }
    }
}
