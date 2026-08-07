<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;

/**
 * Define evidência relevante por documento e conteúdo equivalente.
 *
 * Resolve goldens frágeis que dependem de um único chunkId mesmo quando vários
 * trechos do documento sustentam corretamente a resposta.
 */
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
