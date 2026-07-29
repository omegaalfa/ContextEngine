<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Rag\Question;

final readonly class Retriever
{
    public function __construct(private EmbeddingProvider $embeddings, private VectorStore $store, private RetrievalPolicy $policy = new RetrievalPolicy(), private ?string $collection = null, private string $status = 'active') {}
    /** @return list<VectorSearchResult> */
    public function retrieve(Question $question): array
    {
        return $this->store->search(new VectorSearchQuery($question->tenantId, $this->embeddings->embed($question->content, $question->tenantId), $this->policy, $this->collection, $this->status));
    }
}
