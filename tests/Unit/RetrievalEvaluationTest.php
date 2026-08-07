<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;
use Omegaalfa\ContextEngine\Evaluation\EvaluationStatus;
use Omegaalfa\ContextEngine\Evaluation\GoldenChunkMatcher;
use Omegaalfa\ContextEngine\Evaluation\GoldenMatchMode;
use Omegaalfa\ContextEngine\Evaluation\RetrievalEvaluator;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetrievalEvaluationTest extends TestCase
{
    public function testCalculatesChunkAndDocumentRankingSeparately(): void
    {
        $case = new EvaluationCase(
            'ranking',
            'pergunta',
            relevantChunkIds: ['relevant'],
            relevantDocumentIds: ['relevant-document'],
        );
        $report = new RetrievalEvaluator('tenant')->evaluate($this->retriever([
            $this->searchResult('other', 'other-document', 0.1),
            $this->searchResult('relevant', 'relevant-document', 0.2),
        ]), new EvaluationDataset([$case]));
        $scores = $report->results[0]->scores;

        self::assertSame(1.0, $scores['chunk_recall']->value);
        self::assertSame(0.5, $scores['chunk_precision']->value);
        self::assertSame(0.5, $scores['chunk_mrr']->value);
        self::assertSame(1.0, $scores['chunk_hit_rate']->value);
        self::assertSame(0.0, $scores['chunk_hit_at_1']->value);
        self::assertSame(1.0, $scores['document_recall']->value);
        self::assertSame(0.5, $scores['document_precision']->value);
        self::assertSame(0.5, $scores['document_mrr']->value);
        self::assertSame(0.0, $scores['document_hit_at_1']->value);
        self::assertSame(1, $report->denominator('chunk_recall'));
        self::assertSame(1, $report->denominator('document_recall'));
    }

    public function testMarksRetrievalWithoutGroundTruthNotApplicable(): void
    {
        $dataset = new EvaluationDataset([new EvaluationCase('generation-only', 'pergunta', expectedTerms: ['answer'])]);
        $report = new RetrievalEvaluator('tenant')->evaluate($this->retriever([]), $dataset);

        self::assertSame(EvaluationStatus::NOT_APPLICABLE, $report->results[0]->status);
        self::assertNull($report->metric('chunk_recall'));
        self::assertSame(0, $report->denominator('chunk_recall'));
    }

    public function testNegativeCasePassesWithoutContextAndFailsWithContext(): void
    {
        $dataset = new EvaluationDataset([new EvaluationCase('negative', 'algoritmo inexistente', expectNoEvidence: true)]);
        $passed = new RetrievalEvaluator('tenant')->evaluate($this->retriever([]), $dataset);
        $failed = new RetrievalEvaluator('tenant')->evaluate($this->retriever([$this->searchResult('wrong', 'wrong-document', 0.1)]), $dataset);

        self::assertSame(EvaluationStatus::PASSED, $passed->results[0]->status);
        self::assertSame(EvaluationStatus::FAILED, $failed->results[0]->status);
        self::assertSame(1.0, $passed->metric('no_evidence'));
        self::assertSame(0.0, $failed->metric('no_evidence'));
    }

    public function testPipelineErrorIsNotReportedAsEvaluationFailure(): void
    {
        $dataset = new EvaluationDataset([new EvaluationCase('error', 'pergunta', relevantChunkIds: ['expected'])]);
        $report = new RetrievalEvaluator('tenant')->evaluate($this->retriever([], true), $dataset);

        self::assertSame(EvaluationStatus::ERROR, $report->results[0]->status);
        self::assertStringContainsString(RuntimeException::class, (string) $report->results[0]->error);
    }

    public function testRejectsEmptyGroundTruthAndDuplicateIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EvaluationCase('empty', 'question', relevantChunkIds: []);
    }

    public function testRejectsDuplicateCaseIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EvaluationDataset([
            new EvaluationCase('duplicate', 'one', expectedTerms: ['one']),
            new EvaluationCase('duplicate', 'two', expectedTerms: ['two']),
        ]);
    }

    public function testMatchesGoldenChunksWithAnyAndAllModes(): void
    {
        $chunks = [
            new Chunk('one', 'document', 'tenant', 'Dijkstra usa fila de prioridade.', 0),
            new Chunk('two', 'document', 'tenant', 'Dijkstra encontra caminhos.', 1),
        ];
        $matcher = new GoldenChunkMatcher();

        self::assertSame(['one', 'two'], $matcher->ids($chunks, GoldenMatchMode::ANY, ['Dijkstra', 'fila de prioridade']));
        self::assertSame(['one'], $matcher->ids($chunks, GoldenMatchMode::ALL, ['Dijkstra', 'fila de prioridade']));
    }

    /** @param list<VectorSearchResult> $results */
    private function retriever(array $results, bool $throws = false): Retriever
    {
        $space = new EmbeddingSpace('test', 'fixed', 1);
        $embeddings = new class ($space) implements EmbeddingProvider {
            public function __construct(private readonly EmbeddingSpace $space) {}
            public function space(): EmbeddingSpace
            {
                return $this->space;
            }
            public function embed(string $text, string $tenantId): Embedding
            {
                return new Embedding([1.0], $this->space);
            }
            public function embedBatch(EmbeddingBatchRequest $request): array
            {
                return [];
            }
        };
        $store = new class ($results, $throws) implements VectorStore {
            public function __construct(private readonly array $results, private readonly bool $throws) {}
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
                if ($this->throws) {
                    throw new RuntimeException('store unavailable');
                }
                return $this->results;
            }
            public function deleteChunk(ChunkDeleteQuery $query): int
            {
                return 0;
            }
            public function deleteDocument(DocumentDeleteQuery $query): int
            {
                return 0;
            }
            public function clearCollection(CollectionDeleteQuery $query): int
            {
                return 0;
            }
        };
        return new Retriever($embeddings, $store);
    }

    private function searchResult(string $chunkId, string $documentId, float $distance): VectorSearchResult
    {
        return new VectorSearchResult(new Chunk($chunkId, $documentId, 'tenant', 'content', 0), $distance);
    }
}
