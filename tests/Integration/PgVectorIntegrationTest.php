<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Integration;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Embedding\{EmbeddedChunk,Embedding,EmbeddingSpace};
use Omegaalfa\ContextEngine\Retrieval\{VectorSearchQuery};
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\{DatabaseSettings,QueryBuilder};
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use PHPUnit\Framework\TestCase;

final class PgVectorIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private PgVectorStore $store;
    private QueryBuilder $query;
    protected function setUp(): void
    {
        if (getenv('CONTEXT_ENGINE_RUN_PGVECTOR_TESTS') !== '1') {
            self::markTestSkipped('Set CONTEXT_ENGINE_RUN_PGVECTOR_TESTS=1 to enable pgvector integration tests.');
        }
        $host = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_HOST') ?: '127.0.0.1');
        $port = (int)(getenv('CONTEXT_ENGINE_PGVECTOR_PORT') ?: 54329);
        $database = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?: 'context_engine');
        $user = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?: 'context_engine');
        $password = (string)(getenv('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?: 'context_engine');
        try {
            $settings = new DatabaseSettings('pgsql', $host, $database, $port, $user, $password);
            $connection = new PDOConnection($settings);
            $this->pdo = $connection->pdo();
            $this->query = new QueryBuilder($connection);
            $this->store = new PgVectorStore($this->query);
        } catch (\Throwable $e) {
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
        $spaceA = new EmbeddingSpace('fake', 'model', 3, 'a');
        $spaceB = new EmbeddingSpace('fake', 'model', 3, 'b');
        $this->store->storeBatch([
            $this->embedded('shared', 'tenant-a', 'docs', $spaceA, [1,0,0]),
            $this->embedded('shared', 'tenant-b', 'docs', $spaceA, [1,0,0]),
            $this->embedded('shared', 'tenant-a', 'private', $spaceA, [1,0,0]),
        ]);
        $this->store->storeBatch([$this->embedded('shared', 'tenant-a', 'docs', $spaceB, [0,1,0])]);
        $this->store->storeBatch([$this->embedded('shared', 'tenant-a', 'docs', $spaceA, [1,0,0])]);
        self::assertSame(4, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE chunk_id='shared'")->fetchColumn(), 'Tenant, collection, and vector space must create independent identities while an identical upsert stays idempotent.');
        $results = $this->store->search(new VectorSearchQuery('tenant-a', new Embedding([1,0,0], $spaceA), collection:'docs'));
        self::assertCount(1, $results);
        self::assertSame('shared', $results[0]->chunk->id);
        self::assertSame('tenant-a', $results[0]->chunk->tenantId);
        self::assertSame('docs', $results[0]->chunk->collection);
    }
    public function testPlainInsertWorksWithoutSequenceOrTechnicalId(): void
    {
        $space = new EmbeddingSpace('fake', 'model', 3, 'plain');
        $this->query->insert('context_chunks', [
            'chunk_id' => 'plain', 'document_id' => 'doc', 'tenant_id' => 'tenant', 'collection' => 'docs', 'status' => 'active', 'content' => 'plain insert', 'position' => 0, 'metadata' => '{}',
            'embedding' => new Vector([1,0,0], 3), 'embedding_provider' => $space->provider, 'embedding_model' => $space->model, 'embedding_dimensions' => 3, 'embedding_revision' => $space->revision, 'embedding_space_fingerprint' => $space->fingerprint(),
        ]);
        $this->query->execute();
        self::assertSame(1, (int)$this->pdo->query("SELECT count(*) FROM context_chunks WHERE chunk_id='plain'")->fetchColumn());
        $columns = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='context_chunks'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('id', $columns);
    }
    public function testDatabaseRejectsIncompatibleDimension(): void
    {
        $this->expectException(\Throwable::class);
        $space = new EmbeddingSpace('fake', 'model', 2, 'bad');
        $this->store->storeBatch([$this->embedded('bad', 'tenant-a', 'docs', $space, [1,0])]);
    }
    private function embedded(string $id, string $tenant, string $collection, EmbeddingSpace $space, array $values): EmbeddedChunk
    {
        return new EmbeddedChunk(new Chunk($id, 'doc', $tenant, "content-$id", 0, [], $collection), new Embedding($values, $space));
    }
    private function missingColumns(): array
    {
        $required = ['chunk_id','tenant_id','collection','status','embedding','embedding_provider','embedding_model','embedding_dimensions','embedding_revision','embedding_space_fingerprint'];
        $statement = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='context_chunks'");
        $columns = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_diff($required, $columns));
    }
}
