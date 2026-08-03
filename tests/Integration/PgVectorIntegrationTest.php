<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Integration;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\{EmbeddedChunk,Embedding,EmbeddingSpace};
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Retrieval\{NeighborSearchQuery,VectorSearchQuery};
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
        $required = ['chunk_id','document_version','ingestion_state','tenant_id','collection','status','embedding','embedding_provider','embedding_model','embedding_dimensions','embedding_revision','embedding_space_fingerprint'];
        $statement = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='context_chunks'");
        $columns = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($required, $columns));
    }
}
