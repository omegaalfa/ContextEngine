<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Embedding;

use InvalidArgumentException;

final readonly class EmbeddingBatchRequest
{
    /**
     * @param list<string> $texts
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(public string $tenantId, public array $texts, public EmbeddingSpace $expectedSpace, public array $metadata = [])
    {
        if (trim($tenantId) === '' || $texts === []) {
            throw new InvalidArgumentException('Embedding batch requires tenant and a non-empty text list.');
        }
        foreach ($texts as $text) {
            if ($text === '') {
                throw new InvalidArgumentException('Embedding texts cannot be empty.');
            }
        }
    }
}
