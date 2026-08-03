<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Document;

use InvalidArgumentException;

final readonly class Document
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $content,
        public array  $metadata = [],
        public string $collection = 'default',
        public string $status = 'active'
    ) {
        if (trim($id) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('Document id and tenant id cannot be empty.');
        }
        if (trim($content) === '') {
            throw new InvalidArgumentException('Document content cannot be empty.');
        }
        if (trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Collection and status cannot be empty.');
        }
    }
}
