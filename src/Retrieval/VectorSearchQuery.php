<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Embedding\Embedding;

final readonly class VectorSearchQuery
{
    public function __construct(public string $tenantId, public Embedding $embedding, public RetrievalPolicy $policy = new RetrievalPolicy(), public ?string $collection = null, public string $status = 'active')
    {
        if (trim($tenantId) === '' || $collection !== null && trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Search scope values cannot be empty.');
        }
    }
}
