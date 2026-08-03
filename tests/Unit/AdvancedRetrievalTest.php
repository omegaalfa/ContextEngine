<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\NeighborAwareVectorStore;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\InsufficientContextException;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\FixedNoEvidencePolicy;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\IdentityQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\NeighborSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\QueryMatch;
use Omegaalfa\ContextEngine\Retrieval\ReciprocalRankFusion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use PHPUnit\Framework\TestCase;

final class AdvancedRetrievalTest extends TestCase
{
    public function testIdentityAndHeuristicPlanningAlwaysPreserveOriginal(): void
    {
        $question = new Question('Converta para PHP a função Python optimal_bst com e[i,j].', 'tenant');
        self::assertSame([$question->content], new IdentityQueryRewriter()->rewrite($question)->queries);
        $queries = new HeuristicQueryRewriter()->rewrite($question)->queries;
        self::assertSame($question->content, $queries[0]);
        self::assertContains('optimal_bst', $queries);
        self::assertContains('e[i,j]', $queries);

        $heap = new HeuristicQueryRewriter()->rewrite(new Question('Implemente MAX-HEAPIFY em PHP.', 'tenant'));
        self::assertContains('MAX-HEAPIFY', $heap->queries);
        $comparison = new HeuristicQueryRewriter()->rewrite(new Question('Compare Bellman-Ford e Dijkstra.', 'tenant'));
        self::assertContains('Bellman-Ford', $comparison->queries);
        self::assertContains('Dijkstra', $comparison->queries);
    }

    public function testReciprocalRankFusionDeduplicatesAndUsesDeterministicTieBreaks(): void
    {
        $a = $this->makeResult('a', 0, .3);
        $b = $this->makeResult('b', 1, .2);
        $fusion = new ReciprocalRankFusion();
        $results = $fusion->fuse(['q1' => [$a, $b], 'q2' => [$b, $a]], 2);
        self::assertSame(['b', 'a'], array_map(static fn (VectorSearchResult $r): string => $r->chunk->id, $results));
        self::assertCount(2, $results[0]->matches);
        self::assertEqualsWithDelta($results[0]->fusionScore, $results[1]->fusionScore, .000001);
        self::assertContainsOnlyInstancesOf(QueryMatch::class, $results[0]->matches);
    }

    public function testMultiQueryExpansionAndBudgetPreserveScopeOrderAndHitIdentity(): void
    {
        $space = new EmbeddingSpace('test', 'model', 1, 'revision');
        $provider = new class ($space) implements EmbeddingProvider {
            public function __construct(private EmbeddingSpace $space) {}
            public function space(): EmbeddingSpace
            {
                return $this->space;
            }
            public function embed(string $text, string $tenantId): Embedding
            {
                return new Embedding([str_contains($text, 'optimal_bst') && $text !== 'optimal_bst' ? 1 : 2], $this->space);
            }
            public function embedBatch(EmbeddingBatchRequest $request): array
            {
                return [];
            }
        };
        $hit = new VectorSearchResult(
            new Chunk('hit', 'document', 'tenant', 'main', 1, [], 'algorithms', 'active'),
            .1,
            'version-1',
        );
        $store = new class ($hit) implements NeighborAwareVectorStore {
            /** @var list<NeighborSearchQuery> */
            public array $neighborQueries = [];
            public function __construct(private VectorSearchResult $hit) {}
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
                return [$this->hit];
            }
            public function neighbors(NeighborSearchQuery $query): array
            {
                $this->neighborQueries[] = $query;
                return [
                    new Chunk('before', 'document', 'tenant', 'heading', 0, [], 'algorithms', 'active'),
                    $this->hit->chunk,
                    new Chunk('after', 'document', 'tenant', 'code', 2, [], 'algorithms', 'active'),
                ];
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
        $retriever = new Retriever(
            $provider,
            $store,
            new RetrievalPolicy(limit: 5),
            'algorithms',
            'active',
            new HeuristicQueryRewriter(),
            new NeighborExpansion(1, 1),
            fusedLimit: 2,
            contextChunkLimit: 3,
            maximumContextCharacters: 20,
        );
        $outcome = $retriever->retrieveWithDiagnostics(
            new Question('Converta optimal_bst para PHP.', 'tenant'),
        );
        self::assertSame(['before', 'hit', 'after'], array_map(
            static fn (VectorSearchResult $r): string => $r->chunk->id,
            $outcome->results,
        ));
        self::assertTrue($outcome->results[0]->neighbor);
        self::assertFalse($outcome->results[1]->neighbor);
        self::assertSame('tenant', $store->neighborQueries[0]->tenantId);
        self::assertSame('algorithms', $store->neighborQueries[0]->collection);
        self::assertSame('version-1', $store->neighborQueries[0]->documentVersion);
        self::assertSame($space->fingerprint(), $store->neighborQueries[0]->space->fingerprint());
        self::assertGreaterThan(1, count($outcome->diagnostics->queries));
        self::assertSame(['before', 'after'], $outcome->diagnostics->neighborChunkIds);
    }

    public function testRagDiagnosticsAndCapturedPromptKeepReadableSourceAndOriginalQuestion(): void
    {
        $space = new EmbeddingSpace('test', 'model', 1);
        $provider = new class ($space) implements EmbeddingProvider {
            public function __construct(private EmbeddingSpace $space) {}
            public function space(): EmbeddingSpace
            {
                return $this->space;
            }
            public function embed(string $text, string $tenantId): Embedding
            {
                return new Embedding([1], $this->space);
            }
            public function embedBatch(EmbeddingBatchRequest $request): array
            {
                return [];
            }
        };
        $store = new class () implements VectorStore {
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
                $code = 'def optimal_bst(p, q, n):' . chr(10) . '    return p';
                return [new VectorSearchResult(new Chunk('code', 'book', $query->tenantId, $code, 7), .1, 'v1')];
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
            public array $messages = [];
            public function complete(array $messages): string
            {
                $this->messages = $messages;
                return 'translated';
            }
        };
        $rag = new RagPipeline(new Retriever($provider, $store), new ContextPromptBuilder(), $model);
        $question = new Question('Converta optimal_bst para PHP.', 'tenant');
        $execution = $rag->askWithDiagnostics($question);
        self::assertSame('translated', $execution->answer->content);
        self::assertStringContainsString('    return p', $model->messages[1]->content);
        self::assertStringContainsString($question->content, $model->messages[1]->content);
        self::assertStringNotContainsString('base64', strtolower($model->messages[1]->content));
        self::assertSame(['code'], $execution->diagnostics->retrieval->selectedChunkIds);
        self::assertGreaterThan(0, $execution->diagnostics->promptCharacters);
        self::assertArrayHasKey('model', $execution->diagnostics->timingsMilliseconds);
    }

    public function testNoEvidenceNeverCallsLanguageModelAndStreamingDoesNotSimulateDeltas(): void
    {
        $space = new EmbeddingSpace('test', 'model', 1);
        $provider = new class ($space) implements EmbeddingProvider {
            public function __construct(private EmbeddingSpace $space) {}
            public function space(): EmbeddingSpace
            {
                return $this->space;
            }
            public function embed(string $text, string $tenantId): Embedding
            {
                return new Embedding([1], $this->space);
            }
            public function embedBatch(EmbeddingBatchRequest $request): array
            {
                return [];
            }
        };
        $store = new class () implements VectorStore {
            public function storeBatch(array $chunks): void {}
            public function search(VectorSearchQuery $query): array
            {
                return [];
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
            public int $calls = 0;
            public function complete(array $messages): string
            {
                ++$this->calls;
                return 'invented';
            }
        };
        $streaming = new class () implements StreamingLanguageModel {
            public int $calls = 0;
            public function stream(array $messages): iterable
            {
                ++$this->calls;
                return [];
            }
        };
        $rag = new RagPipeline(
            new Retriever($provider, $store),
            new ContextPromptBuilder(),
            $model,
            $streaming,
            new FixedNoEvidencePolicy('Sem evidências suficientes.'),
        );
        $execution = $rag->askWithDiagnostics(new Question('Explique.', 'tenant'));
        self::assertSame('Sem evidências suficientes.', $execution->answer->content);
        self::assertSame([], $execution->answer->sources);
        self::assertSame(0, $model->calls);
        self::assertFalse($execution->diagnostics->modelCalled);
        self::assertSame(0, $execution->diagnostics->promptCharacters);
        self::assertSame(0.0, $execution->diagnostics->timingsMilliseconds['model']);

        try {
            iterator_to_array($rag->stream(new Question('Explique.', 'tenant')));
            self::fail('Streaming without evidence must fail before calling the provider.');
        } catch (InsufficientContextException $exception) {
            self::assertSame('Sem evidências suficientes.', $exception->getMessage());
        }
        self::assertSame(0, $streaming->calls);
    }

    private function makeResult(string $id, int $position, float $distance): VectorSearchResult
    {
        return new VectorSearchResult(new Chunk($id, 'doc', 'tenant', $id, $position), $distance, 'version');
    }
}
