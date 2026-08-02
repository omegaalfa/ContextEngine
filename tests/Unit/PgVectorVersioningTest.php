<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    private function storeWithoutConnection(): PgVectorStore
    {
        $query = new ReflectionClass(QueryBuilder::class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(QueryBuilder::class, $query);
        return new PgVectorStore($query);
    }
}
