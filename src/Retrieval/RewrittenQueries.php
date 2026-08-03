<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;

final readonly class RewrittenQueries
{
    /** @param non-empty-list<string> $queries */
    public function __construct(public string $original, public array $queries)
    {
        // @phpstan-ignore identical.alwaysFalse
        if (trim($original) === '' || $queries === []) {
            throw new InvalidArgumentException('Rewritten queries require the original question and at least one query.');
        }
        foreach ($queries as $query) {
            if (trim($query) === '') {
                throw new InvalidArgumentException('Retrieval queries cannot be empty.');
            }
        }
        if ($queries[0] !== $original || count($queries) !== count(array_unique($queries))) {
            throw new InvalidArgumentException('The original query must be first and every query must be unique.');
        }
    }
}
