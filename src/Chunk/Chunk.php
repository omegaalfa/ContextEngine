<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Chunk;

use InvalidArgumentException;

final readonly class Chunk
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(public string $id, public string $documentId, public string $tenantId, public string $content, public int $position, public array $metadata = [], public string $collection = 'default', public string $status = 'active')
    {
        if (trim($id) === '' || trim($documentId) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('Chunk identifiers cannot be empty.');
        }
        if (trim($content) === '' || $position < 0) {
            throw new InvalidArgumentException('Chunk content must be non-empty and position non-negative.');
        }
        if (trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Collection and status cannot be empty.');
        }
    }
}
