<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Integration;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\{EmbeddedChunk,Embedding,EmbeddingSpace};
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Retrieval\{LexicalSearchQuery,NeighborSearchQuery,RetrievalPolicy,VectorSearchQuery,VersionSelectionPolicy};
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\{DatabaseSettings,QueryBuilder};
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PgVectorIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PgVectorStore $store;
    private QueryBuilder $query;
    protected function setUp(): void
    {
        if (getenv('CONTEXT_ENGINE_RUN_PGVECTOR_TESTS') !== '1') {
            self::markTestSkipped('Set CONTEXT_ENGINE_RUN_PGVECTOR_TESTS=1 to enable pgvector integration tests.');
        }
        $host = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_HOST') ?: '127.0.0.1');
        $port = (int)(getenv('CONTEXT_ENGINE_PGVECTOR_PORT') ?: 54339);
        $database = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?: 'context_engine');
        $user = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?: 'context_engine');
        $password = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?: 'context_engine');
        try {
            $settings = new DatabaseSettings('pgsql', $host, $database, $port, $user, $password);
            $connection = new PDOConnection($settings);
            $this->pdo = $connection->pdo();
            $this->query = new QueryBuilder($connection);
            $this->store = new PgVectorStore($this->query);
        } catch (Throwable $e) {
            self::fail('Pgvector integration is enabled but service/configuration is unavailable: '.$e->getMessage());
        }
        $missing = $this->missingColumns();
        if ($missing !== []) {
            self::fail('Pgvector integration schema is not provisioned correctly; missing: '.implode(', ', $missing));
        }
        $this->pdo->exec('TRUNCATE TABLE context_chunks');
    }
    public function testPersistenceSearchScopeSpaceAndIdempotency(): void
    {
        $spaceA = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'a');
        $spaceB = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'b');
        $this->store->storeBatch([$this->embedded('shared', 'tenant-a', 'docs', $spaceA, $this->vector(0))]);
        $this->store->storeBatch([$this->embedded('shared', 'tenant-b', 'docs', $spaceA, $this->vector(0))]);
        $this->store->storeBatch([$this->embedded('shared', 'tenant-a', 'private', $spaceA, $this->vector(0))]);
        $this->store->storeBatch([$this->embedded('shared', 'tenant-a', 'docs', $spaceB, $this->vector(1))]);
        $this->store->storeBatch([$this->embedded('shared', 'tenant-a', 'docs', $spaceA, $this->vector(0))]);
        self::assertSame(4, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE chunk_id='shared'")->fetchColumn(), 'Tenant, collection, and vector space must create independent identities while an identical upsert stays idempotent.');
        $results = $this->store->search(new VectorSearchQuery('tenant-a', new Embedding($this->vector(0), $spaceA), collection:'docs'));
        self::assertCount(1, $results);
        self::assertSame('shared', $results[0]->chunk->id);
        self::assertSame('tenant-a', $results[0]->chunk->tenantId);
        self::assertSame('docs', $results[0]->chunk->collection);
    }
    public function testPlainInsertWorksWithoutSequenceOrTechnicalId(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'plain');
        $this->query->insert('context_chunks', [
            'chunk_id' => 'plain', 'document_id' => 'doc', 'document_version' => 'plain-version', 'ingestion_state' => 'active', 'tenant_id' => 'tenant', 'collection' => 'docs', 'status' => 'active', 'content' => 'plain insert', 'position' => 0, 'metadata' => '{}',
            'embedding' => new Vector($this->vector(0), 1024), 'embedding_provider' => $space->provider, 'embedding_model' => $space->model, 'embedding_dimensions' => 1024, 'embedding_revision' => $space->revision, 'embedding_space_fingerprint' => $space->fingerprint(),
        ]);
        $this->query->execute();
        self::assertSame(1, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE chunk_id='plain'")->fetchColumn());
        $columns = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='context_chunks'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('id', $columns);
    }
    public function testDatabaseRejectsIncompatibleDimension(): void
    {
        $this->expectException(Throwable::class);
        $space = new EmbeddingSpace('fake', 'model', 2, 'bad');
        $this->store->storeBatch([$this->embedded('bad', 'tenant-a', 'docs', $space, [1,0])]);
    }
    public function testStagedVersionIsInvisibleAndActivationAtomicallyReplacesPreviousVersion(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'versions');
        $old = new DocumentVersion(new Document('versioned-doc', 'tenant-a', 'old document', collection: 'docs'), $space, 'splitter-v1');
        $new = new DocumentVersion(new Document('versioned-doc', 'tenant-a', 'new document', collection: 'docs'), $space, 'splitter-v1');

        $this->store->beginVersion($old);
        $this->store->stageBatch($old, [$this->embeddedForDocument('old-chunk', 'versioned-doc', 'tenant-a', 'docs', $space, $this->vector(0))]);
        $this->store->activateVersion($old);

        $this->store->beginVersion($new);
        $this->store->stageBatch($new, [$this->embeddedForDocument('new-chunk', 'versioned-doc', 'tenant-a', 'docs', $space, $this->vector(0))]);
        $beforeActivation = $this->store->search(new VectorSearchQuery('tenant-a', new Embedding($this->vector(0), $space), collection: 'docs'));
        self::assertSame('old-chunk', $beforeActivation[0]->chunk->id);

        $this->store->failVersion($new);
        self::assertSame('old-chunk', $this->store->search(new VectorSearchQuery('tenant-a', new Embedding($this->vector(0), $space), collection: 'docs'))[0]->chunk->id);

        $this->store->beginVersion($new);
        $this->store->stageBatch($new, [$this->embeddedForDocument('new-chunk', 'versioned-doc', 'tenant-a', 'docs', $space, $this->vector(0))]);
        $this->store->activateVersion($new);
        self::assertSame('new-chunk', $this->store->search(new VectorSearchQuery('tenant-a', new Embedding($this->vector(0), $space), collection: 'docs'))[0]->chunk->id);
        self::assertSame(1, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE document_id='versioned-doc' AND ingestion_state='active'")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE document_id='versioned-doc' AND ingestion_state='superseded'")->fetchColumn());
    }
    public function testBatchStatementIsAtomicWhenOneRowFails(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'atomic');
        try {
            $this->store->storeBatch([
                $this->embedded('duplicate', 'tenant-a', 'docs', $space, $this->vector(0)),
                $this->embedded('duplicate', 'tenant-a', 'docs', $space, $this->vector(1)),
            ]);
            self::fail('Expected PostgreSQL to reject affecting the same conflict key twice.');
        } catch (Throwable) {
            self::assertSame(0, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE chunk_id='duplicate'")->fetchColumn());
        }
    }

    public function testNeighborsStayInsideActiveDocumentVersionAndVectorSpace(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'neighbors');
        $document = new Document('neighbor-doc', 'tenant-a', 'version content', collection: 'docs');
        $version = new DocumentVersion($document, $space, 'splitter-v1');
        $chunks = [];
        foreach (['before', 'hit', 'after'] as $position => $id) {
            $chunks[] = new EmbeddedChunk(
                new Chunk($id, $document->id, 'tenant-a', $id, $position, [], 'docs'),
                new Embedding($this->vector(0), $space),
            );
        }
        $this->store->beginVersion($version);
        $this->store->stageBatch($version, $chunks);
        $this->store->activateVersion($version);
        $neighbors = $this->store->neighbors(new NeighborSearchQuery(
            'tenant-a',
            'docs',
            'active',
            $document->id,
            $version->id,
            $space,
            1,
            1,
            1,
        ));
        self::assertSame(
            ['before', 'hit', 'after'],
            array_map(static fn (Chunk $chunk): string => $chunk->id, $neighbors),
        );
        self::assertSame([0, 1, 2], array_map(static fn (Chunk $chunk): int => $chunk->position, $neighbors));

        $otherSpace = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'other-space');
        self::assertSame([], $this->store->neighbors(new NeighborSearchQuery(
            'tenant-a',
            'docs',
            'active',
            $document->id,
            $version->id,
            $otherSpace,
            1,
            1,
            1,
        )));
    }
    public function testDeletionOperationsNeverEscapeTenantCollectionOrVectorSpace(): void
    {
        $spaceA = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'delete-a');
        $spaceB = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'delete-b');
        $this->store->storeBatch([
            $this->embeddedForDocument('shared', 'doc-1', 'tenant-a', 'docs', $spaceA, $this->vector(0)),
            $this->embeddedForDocument('second', 'doc-1', 'tenant-a', 'docs', $spaceA, $this->vector(1)),
        ]);
        $this->store->storeBatch([$this->embeddedForDocument('shared', 'doc-1', 'tenant-a', 'docs', $spaceB, $this->vector(1))]);
        $this->store->storeBatch([$this->embeddedForDocument('shared', 'doc-1', 'tenant-b', 'docs', $spaceA, $this->vector(0))]);
        $this->store->storeBatch([$this->embeddedForDocument('shared', 'doc-1', 'tenant-a', 'private', $spaceA, $this->vector(0))]);
        $this->store->storeBatch([$this->embeddedForDocument('other', 'doc-2', 'tenant-a', 'docs', $spaceA, $this->vector(0))]);

        self::assertSame(1, $this->store->deleteChunk(new ChunkDeleteQuery('tenant-a', 'docs', 'shared', $spaceA)));
        self::assertSame(1, $this->store->deleteDocument(new DocumentDeleteQuery('tenant-a', 'docs', 'doc-1', $spaceA)));
        self::assertSame(1, $this->store->deleteDocument(new DocumentDeleteQuery('tenant-a', 'docs', 'doc-1')));
        self::assertSame(1, $this->store->clearCollection(new CollectionDeleteQuery('tenant-a', 'docs')));

        self::assertSame(2, (int)$this->pdo->query('SELECT count(*) FROM context_chunks')->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE tenant_id='tenant-b' AND collection='docs'")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE tenant_id='tenant-a' AND collection='private'")->fetchColumn());

        self::assertSame(1, $this->store->deleteDocument(new DocumentDeleteQuery('tenant-a')));
        self::assertSame(1, (int)$this->pdo->query('SELECT count(*) FROM context_chunks')->fetchColumn(), 'Tenant-wide deletion must not affect another tenant.');
    }

    public function testLexicalSearchFindsExactIdentifiersInRelevanceOrder(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'lexical');
        $this->store->storeBatch([
            $this->embeddedForDocument('generic', 'doc-1', 'tenant-a', 'docs', $space, $this->vector(0)),
            new EmbeddedChunk(new Chunk('sku', 'doc-2', 'tenant-a', 'SKU-ABX-991 docs SKU-ABX-991', 0, [], 'docs', 'active'), new Embedding($this->vector(0), $space)),
            new EmbeddedChunk(new Chunk('error', 'doc-3', 'tenant-a', 'ERR_PAYMENT_1047 happened ERR_PAYMENT_1047 ERR_PAYMENT_1047', 0, [], 'docs', 'active'), new Embedding($this->vector(0), $space)),
        ]);

        $results = $this->store->searchLexical(new LexicalSearchQuery(
            tenantId: 'tenant-a',
            terms: 'ERR_PAYMENT_1047',
            policy: new RetrievalPolicy(limit: 10),
            collection: 'docs',
            status: 'active',
        ));

        self::assertNotSame([], $results);
        $ids = array_map(static fn (\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult $result): string => $result->chunk->id, $results);
        self::assertContains('error', $ids);
        self::assertSame('error', $ids[0]);
        foreach ($results as $result) {
            self::assertTrue(is_finite($result->distance));
            self::assertGreaterThanOrEqual(0.0, $result->distance);
        }
    }

    public function testLexicalSearchRespectsTenantCollectionStatusVersionAndEmptyResult(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 1024, 'lexical-scope');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tenantA = new DocumentVersion(
            new Document('doc-versioned', 'tenant-a', 'v1', collection: 'docs'),
            $space,
            'splitter-v1',
            validFrom: $now->modify('-2 hours'),
            validUntil: $now->modify('-1 hour'),
        );
        $tenantANext = new DocumentVersion(
            new Document('doc-versioned', 'tenant-a', 'v2', collection: 'docs'),
            $space,
            'splitter-v1',
            validFrom: $now->modify('-10 minutes'),
            validUntil: $now->modify('+1 hour'),
            revision: 2,
            supersedesVersionId: $tenantA->id,
        );

        $this->store->beginVersion($tenantA);
        $this->store->stageBatch($tenantA, [new EmbeddedChunk(new Chunk('tenant-a-v1', 'doc-versioned', 'tenant-a', 'ContextPromptBuilder v1', 0, [], 'docs', 'active'), new Embedding($this->vector(0), $space))]);
        $this->store->activateVersion($tenantA);

        $this->store->beginVersion($tenantANext);
        $this->store->stageBatch($tenantANext, [new EmbeddedChunk(new Chunk('tenant-a-v2', 'doc-versioned', 'tenant-a', 'ContextPromptBuilder v2.4.17', 0, [], 'docs', 'active'), new Embedding($this->vector(0), $space))]);

        $this->store->storeBatch([
            new EmbeddedChunk(new Chunk('tenant-b', 'doc-tenant-b', 'tenant-b', 'ContextPromptBuilder', 0, [], 'docs', 'active'), new Embedding($this->vector(0), $space)),
        ]);
        $this->store->storeBatch([
            new EmbeddedChunk(new Chunk('private', 'doc-private', 'tenant-a', 'ContextPromptBuilder', 0, [], 'private', 'active'), new Embedding($this->vector(0), $space)),
        ]);
        $this->store->storeBatch([
            new EmbeddedChunk(new Chunk('inactive', 'doc-inactive', 'tenant-a', 'ContextPromptBuilder', 0, [], 'docs', 'inactive'), new Embedding($this->vector(0), $space)),
        ]);

        $scoped = $this->store->searchLexical(new LexicalSearchQuery(
            tenantId: 'tenant-a',
            terms: 'ContextPromptBuilder',
            policy: new RetrievalPolicy(limit: 10),
            collection: 'docs',
            status: 'active',
        ));
        self::assertContains('tenant-a-v1', array_map(static fn (\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult $result): string => $result->chunk->id, $scoped));
        self::assertNotContains('tenant-b', array_map(static fn (\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult $result): string => $result->chunk->id, $scoped));
        self::assertNotContains('private', array_map(static fn (\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult $result): string => $result->chunk->id, $scoped));
        self::assertNotContains('inactive', array_map(static fn (\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult $result): string => $result->chunk->id, $scoped));

        $this->store->activateVersion($tenantANext);

        $validAt = $this->store->searchLexical(new LexicalSearchQuery(
            tenantId: 'tenant-a',
            terms: 'v2.4.17',
            policy: new RetrievalPolicy(limit: 10),
            collection: 'docs',
            status: 'active',
            versionSelectionPolicy: VersionSelectionPolicy::validAt($now),
        ));
        self::assertContains('tenant-a-v2', array_map(static fn (\Omegaalfa\ContextEngine\Retrieval\VectorSearchResult $result): string => $result->chunk->id, $validAt));

        $empty = $this->store->searchLexical(new LexicalSearchQuery(
            tenantId: 'tenant-a',
            terms: 'NO_MATCH_TERM_1234',
            policy: new RetrievalPolicy(limit: 10),
            collection: 'docs',
            status: 'active',
        ));
        self::assertSame([], $empty);
    }
    private function embedded(string $id, string $tenant, string $collection, EmbeddingSpace $space, array $values): EmbeddedChunk
    {
        return $this->embeddedForDocument($id, 'doc', $tenant, $collection, $space, $values);
    }
    private function embeddedForDocument(string $id, string $documentId, string $tenant, string $collection, EmbeddingSpace $space, array $values): EmbeddedChunk
    {
        return new EmbeddedChunk(new Chunk($id, $documentId, $tenant, "content-$id", 0, [], $collection), new Embedding($values, $space));
    }
    /** @return list<float> */
    private function vector(int $axis): array
    {
        $values = array_fill(0, 1024, 0.0);
        $values[$axis] = 1.0;
        return $values;
    }
    private function missingColumns(): array
    {
        $required = ['chunk_id','document_version','ingestion_state','tenant_id','collection','status','search_vector','embedding','embedding_provider','embedding_model','embedding_dimensions','embedding_revision','embedding_space_fingerprint'];
        $statement = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='context_chunks'");
        $columns = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($required, $columns));
    }
}
