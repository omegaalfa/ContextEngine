<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class LexicalSearchQuery
{
    public function __construct(
        public string $tenantId,
        public string $terms,
        public RetrievalPolicy $policy = new RetrievalPolicy(),
        public ?string $collection = null,
        public string $status = 'active',
        public ?VersionSelectionPolicy $versionSelectionPolicy = null,
    ) {
        if (trim($tenantId) === '' || trim($terms) === '' || $collection !== null && trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Lexical search scope and terms cannot be empty.');
        }
    }
}
