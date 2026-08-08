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
        public string $textSearchConfiguration = 'portuguese',
    ) {
        if (trim($tenantId) === '' || trim($terms) === '' || $collection !== null && trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Lexical search scope and terms cannot be empty.');
        }
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $textSearchConfiguration) !== 1) {
            throw new InvalidArgumentException('PostgreSQL text search configuration must be a safe identifier.');
        }
    }
}
