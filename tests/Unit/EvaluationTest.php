<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDatasetLoader;
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;
use Omegaalfa\ContextEngine\Evaluation\RelevantEvidence;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use PHPUnit\Framework\TestCase;

final class EvaluationTest extends TestCase
{
    public function testEvaluatesRetrievalAndGenerationUsingRealPipelineDiagnostics(): void
    {
        $dataset = new EvaluationDataset([
            new EvaluationCase(
                id: 'quicksort',
                question: 'Como funciona o quicksort?',
                expectedAnswer: 'O quicksort usa divisão e conquista.',
                relevantChunkIds: ['chunk-relevant'],
                relevantDocumentIds: ['algorithms'],
                expectedTerms: ['quicksort', 'divisão'],
            ),
        ], 'Algoritmos');

        $report = new RagEvaluator('tenant')->evaluate($this->pipeline(), $dataset);
        $result = $report->results[0];

        self::assertTrue($result->passed, json_encode($result->scores, JSON_THROW_ON_ERROR));
        self::assertSame(1.0, $result->retrieval->recall);
        self::assertSame(0.5, $result->retrieval->precision);
        self::assertSame(0.5, $result->retrieval->reciprocalRank);
        self::assertSame(1.0, $result->generation->strictExactMatch);
        self::assertSame(1.0, $result->generation->normalizedExactMatch);
        self::assertSame(1.0, $result->generation->containsExpectedTerms);
        self::assertSame(1, $report->passedCases);
        self::assertSame(2, $report->retrievedChunks);
        self::assertSame(2, $report->selectedChunks);
        self::assertSame(['chunk-other', 'chunk-relevant'], $result->execution?->diagnostics->retrieval->expandedChunkIds);
        self::assertSame(['chunk-other', 'chunk-relevant'], $result->execution?->diagnostics->retrieval->relevanceSelectedChunkIds);
    }

    public function testUsesDocumentExpectationsWhenChunkIdsAreAbsent(): void
    {
        $report = new RagEvaluator('tenant')->evaluate($this->pipeline(), new EvaluationDataset([
            new EvaluationCase('document', 'Pergunta', relevantDocumentIds: ['algorithms']),
        ]));

        self::assertSame(1.0, $report->results[0]->scores['document_recall']->value);
        self::assertSame(0.5, $report->results[0]->scores['document_precision']->value);
    }

    public function testMatchesRelevantEvidenceByDocumentAndEquivalentText(): void
    {
        $report = new RagEvaluator('tenant')->evaluate($this->pipeline(), new EvaluationDataset([
            new EvaluationCase(
                'evidence',
                'Pergunta',
                relevantEvidence: [new RelevantEvidence('algorithms', [['quick sort', 'quicksort'], ['divisão']])],
            ),
        ]));

        self::assertSame(1.0, $report->results[0]->scores['evidence_recall']->value);
        self::assertTrue($report->results[0]->passed);
    }

    public function testEvaluatesAlternativeExpectedTermGroups(): void
    {
        $report = new RagEvaluator('tenant')->evaluate($this->pipeline(), new EvaluationDataset([
            new EvaluationCase('terms', 'Pergunta', expectedTermGroups: [
                ['quick sort', 'quicksort'],
                ['divide and conquer', 'divisão e conquista'],
            ]),
        ]));

        self::assertTrue($report->results[0]->passed);
        self::assertNull($report->averageRecall);
        self::assertSame(1.0, $report->results[0]->generation->containsExpectedTerms);
    }

    public function testLoadsDatasetFromJson(): void
    {
        $dataset = new EvaluationDatasetLoader()->fromJson(<<<'JSON'
            [{
              "id": "dijkstra",
              "question": "Como funciona Dijkstra?",
              "expectedTerms": ["menor caminho"],
              "relevantDocumentIds": ["algorithms"],
              "metadata": {"category": "graphs"}
            }]
            JSON, 'Algorithms');

        self::assertSame('Algorithms', $dataset->name);
        self::assertCount(1, $dataset);
        self::assertSame(['menor caminho'], $dataset->cases[0]->expectedTerms);
        self::assertSame('graphs', $dataset->cases[0]->metadata['category']);
    }

    public function testSeparatesStrictAndNormalizedExactMatch(): void
    {
        $dataset = new EvaluationDataset([
            new EvaluationCase('exact', 'Pergunta', expectedAnswer: 'O QUICKSORT usa divisão e conquista'),
        ]);
        $result = new RagEvaluator('tenant')->evaluate($this->pipeline(), $dataset)->results[0];

        self::assertSame(0.0, $result->generation->strictExactMatch);
        self::assertSame(1.0, $result->generation->normalizedExactMatch);
    }

    private function pipeline(): RagPipeline
    {
        $space = new EmbeddingSpace('test', 'fixed', 1);
        $embedding = new class ($space) implements EmbeddingProvider {
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
        $results = [
            new VectorSearchResult(new Chunk('chunk-other', 'other', 'tenant', 'Conteúdo secundário.', 0), 0.1),
            new VectorSearchResult(new Chunk('chunk-relevant', 'algorithms', 'tenant', 'Quicksort usa divisão e conquista.', 1), 0.2),
        ];
        $store = new class ($results) implements VectorStore {
            /** @param list<VectorSearchResult> $results */
            public function __construct(private readonly array $results) {}
            /** @param list<EmbeddedChunk> $chunks */
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
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
        $model = new class () implements LanguageModel {
            public function complete(array $messages): string
            {
                return 'O quicksort usa divisão e conquista.';
            }
        };

        return new RagPipeline(new Retriever($embedding, $store), new ContextPromptBuilder(), $model);
    }
}
