<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

interface EmbeddingProvider
{
    public function space(): EmbeddingSpace;
    public function embed(string $text, string $tenantId): Embedding;
    /**
     * @return list<Embedding>
     */
    public function embedBatch(EmbeddingBatchRequest $request): array;
}
