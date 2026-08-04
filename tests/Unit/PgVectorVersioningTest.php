<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersionStatus;
use Omegaalfa\ContextEngine\Retrieval\VersionSelectionPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VersionedSourceProvenance;
use Omegaalfa\ContextEngine\VectorStore\PgVectorSchema;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

final class PgVectorVersioningTest extends TestCase
{
    public function testEmptyBatchIsANoOp(): void
    {
        $this->storeWithoutConnection()->storeBatch([]);
        self::addToAssertionCount(1);
    }

    public function testRejectsInvalidBatchItemBeforeUsingTheQueryBuilder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var array $invalid */
        $invalid = [new stdClass()];
        $this->storeWithoutConnection()->storeBatch($invalid);
    }

    public function testRejectsMixedTenantOrCollectionBatch(): void
    {
        $space = new EmbeddingSpace('fake', 'model', 1);
        $this->expectException(InvalidArgumentException::class);
        $this->storeWithoutConnection()->storeBatch([
            new EmbeddedChunk(new Chunk('a', 'doc', 'tenant-a', 'a', 0, [], 'docs'), new Embedding([1.0], $space)),
            new EmbeddedChunk(new Chunk('b', 'doc', 'tenant-b', 'b', 1, [], 'docs'), new Embedding([1.0], $space)),
        ]);
    }

    public function testDifferentSpacesUseACompositeConflictKey(): void
    {
        $builder = new class () extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('pgsql');
            }
        };
        $sql = $builder->insertBatch('context_chunks', [[
            'tenant_id' => 'tenant-a',
            'collection' => 'docs',
            'chunk_id' => 'chunk-1',
            'embedding_space_fingerprint' => 'space-a',
            'document_version' => 'version-a',
            'embedding' => new Vector([1.0], 1),
        ]])->onConflict(['tenant_id', 'collection', 'chunk_id', 'embedding_space_fingerprint', 'document_version'])->doUpdate(['embedding'])->getQuerySql();
        self::assertStringContainsString('ON CONFLICT ("tenant_id", "collection", "chunk_id", "embedding_space_fingerprint", "document_version")', $sql);
        self::assertStringContainsString('CAST(:embedding_0 AS vector)', $sql);
        self::assertStringNotContainsString('RETURNING', $sql);
        self::assertStringNotContainsString('"id"', $sql);
    }

    public function testSearchAppliesTemporalPolicyToQueryBuilder(): void
    {
        $builder = new ReflectionClass(QueryBuilder::class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(QueryBuilder::class, $builder);
        $store = new PgVectorStore($builder);

        $method = new ReflectionMethod(PgVectorStore::class, 'applyVersionSelection');
        $method->setAccessible(true);
        $method->invoke($store, $builder, VersionSelectionPolicy::validAt(new \DateTimeImmutable('2026-01-01 00:00:00')));

        $sql = $builder->getQuerySql();
        self::assertStringContainsString('valid_from', $sql);
        self::assertStringContainsString('valid_until', $sql);
    }

    public function testBuildsProvenanceFromPersistedVersionColumns(): void
    {
        $builder = new ReflectionClass(QueryBuilder::class)->newInstanceWithoutConstructor();
        $schema = new PgVectorSchema();
        $store = new PgVectorStore($builder, $schema);

        $method = new ReflectionMethod(PgVectorStore::class, 'provenanceFromRow');
        $method->setAccessible(true);
        $provenance = $method->invoke($store, [
            $schema->documentVersion => 'version-42',
            $schema->versionStatus => 'active',
            $schema->versionRevision => 7,
            $schema->validFrom => '2026-01-01 00:00:00',
            $schema->validUntil => '2027-01-01 00:00:00',
            $schema->supersedesVersionId => 'version-41',
        ]);

        self::assertInstanceOf(VersionedSourceProvenance::class, $provenance);
        self::assertSame('version-42', $provenance->documentVersionId);
        self::assertSame(7, $provenance->revision);
        self::assertSame('active', $provenance->status);
        self::assertSame('2026-01-01 00:00:00', $provenance->validFrom?->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-01 00:00:00', $provenance->validUntil?->format('Y-m-d H:i:s'));
        self::assertSame('version-41', $provenance->supersedesVersionId);
    }

    public function testRowsPersistVersionMetadataForStagedVersions(): void
    {
        $space = new EmbeddingSpace('fake', 'model', 1);
        $document = new Document('doc-1', 'tenant-a', 'content', [], 'docs');
        $version = new DocumentVersion(
            $document,
            $space,
            'fingerprint',
            DocumentVersionStatus::ACTIVE,
            new \DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new \DateTimeImmutable('2027-01-01 00:00:00+00:00'),
            7,
            'version-41',
        );
        $store = $this->storeWithoutConnection();
        $method = new ReflectionMethod(PgVectorStore::class, 'rows');
        $method->setAccessible(true);
        $rows = $method->invoke($store, [
            new EmbeddedChunk(new Chunk('chunk-1', 'doc-1', 'tenant-a', 'content', 0, [], 'docs'), new Embedding([1.0], $space)),
        ], $version);

        self::assertArrayHasKey('version_status', $rows[0]);
        self::assertSame('active', $rows[0]['version_status']);
        self::assertSame(7, $rows[0]['version_revision']);
        self::assertSame('2026-01-01 00:00:00.000000', $rows[0]['valid_from']);
        self::assertSame('2027-01-01 00:00:00.000000', $rows[0]['valid_until']);
        self::assertSame('version-41', $rows[0]['supersedes_version_id']);
    }

    private function storeWithoutConnection(): PgVectorStore
    {
        $query = new ReflectionClass(QueryBuilder::class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(QueryBuilder::class, $query);
        return new PgVectorStore($query);
    }
}
