<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;

final readonly class RelevantEvidence
{
    /** @param list<list<string>> $requiredTextGroups */
    public function __construct(public string $documentId, public array $requiredTextGroups = [])
    {
        if (trim($documentId) === '') {
            throw new InvalidArgumentException('Relevant evidence document id cannot be empty.');
        }
        foreach ($requiredTextGroups as $group) {
            if ($group === [] || array_any($group, static fn (string $term): bool => trim($term) === '')) {
                throw new InvalidArgumentException('Relevant evidence text groups require non-empty alternatives.');
            }
        }
    }
}
