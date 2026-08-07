<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;

final readonly class VectorSearchResult
{
    /** @param list<QueryMatch> $matches */
    public function __construct(
        public Chunk $chunk,
        public float $distance,
        public ?string $documentVersion = null,
        public bool $neighbor = false,
        public ?float $fusionScore = null,
        public array $matches = [],
        public ?VersionedSourceProvenance $provenance = null,
        public ?float $lexicalScore = null,
        public ?float $rerankerScore = null,
    ) {
        if (!is_finite($distance) || $distance < 0) {
            throw new InvalidArgumentException('Vector distance must be finite and non-negative.');
        }
        if ($documentVersion !== null && trim($documentVersion) === ''
            || $fusionScore !== null && (!is_finite($fusionScore) || $fusionScore < 0)
            || $lexicalScore !== null && (!is_finite($lexicalScore) || $lexicalScore < 0)
            || $rerankerScore !== null && (!is_finite($rerankerScore) || $rerankerScore < 0 || $rerankerScore > 1)) {
            throw new InvalidArgumentException('Search result diagnostics are invalid.');
        }
    }
}
