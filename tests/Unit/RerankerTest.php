<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\Reranker;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Exception\RerankerException;
use Omegaalfa\ContextEngine\Retrieval\DeterministicTextualReranker;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use PHPUnit\Framework\TestCase;

final class RerankerTest extends TestCase
{
    public function testTextualRerankerPreservesScoresAndMovesRelevantCandidate(): void
    {
        $results = [
            $this->searchResult('other', 'Conteúdo genérico.', 0.1, 0.03),
            $this->searchResult('dijkstra', 'Dijkstra encontra o menor caminho.', 0.4, 0.02),
        ];
        $ranked = new DeterministicTextualReranker()->rerank(new Question('Como funciona Dijkstra?', 'tenant'), $results);

        self::assertSame(['dijkstra', 'other'], array_map(static fn ($result): string => $result->chunk->id, $ranked));
        self::assertSame(0.4, $ranked[0]->distance);
        self::assertSame(0.02, $ranked[0]->fusionScore);
        self::assertSame(0.5, $ranked[0]->rerankerScore);
    }

    public function testRetrieverReportsRanksBeforeAndAfterReranking(): void
    {
        $retriever = $this->retriever([
            $this->searchResult('other', 'Conteúdo genérico.', 0.1),
            $this->searchResult('dijkstra', 'Dijkstra encontra o menor caminho.', 0.4),
        ], new DeterministicTextualReranker());
        $outcome = $retriever->retrieveWithDiagnostics(new Question('Como funciona Dijkstra?', 'tenant'));

        self::assertSame(['other', 'dijkstra'], $outcome->diagnostics->fusedChunkIds);
        self::assertSame(['dijkstra', 'other'], $outcome->diagnostics->rerankedChunkIds);
        self::assertSame(2, $outcome->diagnostics->reranking[0]->rankBefore);
        self::assertSame(1, $outcome->diagnostics->reranking[0]->rankAfter);
        self::assertSame(0.4, $outcome->diagnostics->reranking[0]->vectorDistance);
        self::assertNotNull($outcome->diagnostics->reranking[0]->fusionScore);
        self::assertSame(0.5, $outcome->diagnostics->reranking[0]->rerankerScore);
        self::assertSame('DeterministicTextualReranker', $outcome->diagnostics->rerankerName);
        self::assertSame(2, $outcome->diagnostics->rerankerCandidateCount);
        self::assertSame(2, $outcome->diagnostics->rerankerReturnedCount);
        self::assertArrayHasKey('reranking', $outcome->diagnostics->timingsMilliseconds);
    }

    public function testRetrieverRejectsRerankerThatDropsCandidates(): void
    {
        $reranker = new class () implements Reranker {
            public function rerank(Question $question, array $results): array { return array_slice($results, 0, 1); }
        };
        $retriever = $this->retriever([
            $this->searchResult('one', 'Um.', 0.1),
            $this->searchResult('two', 'Dois.', 0.2),
        ], $reranker);

        $this->expectException(InvalidArgumentException::class);
        $retriever->retrieve(new Question('Pergunta', 'tenant'));
    }

    public function testRetrieverFallsBackToRrfOrderWhenRerankerFailsOperationally(): void
    {
        $reranker = new class () implements Reranker {
            public function rerank(Question $question, array $results): array
            {
                throw new RerankerException('Remote reranker failed.', timedOut: true);
            }
        };
        $retriever = $this->retriever([
            $this->searchResult('one', 'Um.', 0.1),
            $this->searchResult('two', 'Dois.', 0.2),
        ], $reranker);
        $outcome = $retriever->retrieveWithDiagnostics(new Question('Pergunta', 'tenant'));

        self::assertSame(['one', 'two'], array_map(static fn ($result): string => $result->chunk->id, $outcome->results));
        self::assertSame(1, $outcome->diagnostics->rerankerFailureCount);
        self::assertSame(1, $outcome->diagnostics->rerankerFallbackCount);
        self::assertTrue($outcome->diagnostics->rerankerTimedOut);
        self::assertSame('Remote reranker failed.', $outcome->diagnostics->rerankerError);
    }

    /** @param list<VectorSearchResult> $results */
    private function retriever(array $results, Reranker $reranker): Retriever
    {
        $space = new EmbeddingSpace('test', 'fixed', 1);
        $embedding = new class ($space) implements EmbeddingProvider {
            public function __construct(private readonly EmbeddingSpace $space) {}
            public function space(): EmbeddingSpace { return $this->space; }
            public function embed(string $text, string $tenantId): Embedding { return new Embedding([1.0], $this->space); }
            public function embedBatch(EmbeddingBatchRequest $request): array { return []; }
        };
        $store = new class ($results) implements VectorStore {
            public function __construct(private readonly array $results) {}
            /** @param list<EmbeddedChunk> $chunks */
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array { return $this->results; }
            public function deleteChunk(ChunkDeleteQuery $query): int { return 0; }
            public function deleteDocument(DocumentDeleteQuery $query): int { return 0; }
            public function clearCollection(CollectionDeleteQuery $query): int { return 0; }
        };
        return new Retriever($embedding, $store, reranker: $reranker);
    }

    private function searchResult(string $id, string $content, float $distance, ?float $fusionScore = null): VectorSearchResult
    {
        return new VectorSearchResult(new Chunk($id, 'document', 'tenant', $content, 0), $distance, fusionScore: $fusionScore);
    }
}
