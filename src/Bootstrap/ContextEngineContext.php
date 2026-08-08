<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Ingestion\IngestionReport;
use Omegaalfa\ContextEngine\Rag\Answer;
use Omegaalfa\ContextEngine\Rag\AnswerDelta;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagExecution;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

final readonly class ContextEngineContext
{
    /**
     * @param Retriever $retriever
     * @param IngestionPipeline $ingestion
     * @param RagPipeline $rag
     * @param EmbeddingProvider $embeddings
     * @param VectorStore $store
     */
    public function __construct(
        public Retriever         $retriever,
        public IngestionPipeline $ingestion,
        public RagPipeline       $rag,
        public EmbeddingProvider $embeddings,
        public VectorStore       $store,
    ) {}

    /**
     * @param DocumentLoader $loader
     * @return IngestionReport
     */
    public function ingest(DocumentLoader $loader): IngestionReport
    {
        return $this->ingestion->ingest($loader);
    }

    /** @return list<VectorSearchResult> */
    public function search(Question|string $question, ?string $tenantId = null): array
    {
        return $this->retriever->retrieve($this->question($question, $tenantId));
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return Answer
     * @throws \JsonException
     */
    public function ask(Question|string $question, ?string $tenantId = null): Answer
    {
        return $this->rag->ask($question, $tenantId);
    }

    /** @return iterable<AnswerDelta> */
    public function stream(Question|string $question, ?string $tenantId = null): iterable
    {
        return $this->rag->stream($question, $tenantId);
    }

    /** @return list<VectorSearchResult> */
    public function searchWithDiagnostics(Question|string $question, ?string $tenantId = null): array
    {
        return $this->retriever->retrieveWithDiagnostics($this->question($question, $tenantId))->results;
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return RagExecution
     */
    public function askWithDiagnostics(Question|string $question, ?string $tenantId = null): RagExecution
    {
        return $this->rag->askWithDiagnostics($question, $tenantId);
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return Question
     */
    private function question(Question|string $question, ?string $tenantId): Question
    {
        if ($question instanceof Question) {
            return $question;
        }

        if ($tenantId === null) {
            throw new InvalidArgumentException('tenantId is required when question is a string.');
        }

        return new Question($question, $tenantId);
    }
}
