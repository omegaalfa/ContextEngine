<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;

final readonly class CollectionDeleteQuery
{
    /**
     * @param string $tenantId
     * @param string $collection
     */
    public function __construct(public string $tenantId, public string $collection)
    {
        if (trim($tenantId) === '' || trim($collection) === '') {
            throw new InvalidArgumentException('Collection deletion scope values cannot be empty.');
        }
    }
}
