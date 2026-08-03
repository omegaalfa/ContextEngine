<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use InvalidArgumentException;

final readonly class Question
{
    /**
     * @param string $content
     * @param string $tenantId
     */
    public function __construct(public string $content, public string $tenantId)
    {
        if (trim($content) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('Question and tenant id cannot be empty.');
        }
    }
}
