<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;

final class PgVectorVersioningTest extends TestCase
{
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
            'embedding' => new Vector([1.0], 1),
        ]])->onConflict(['tenant_id', 'collection', 'chunk_id', 'embedding_space_fingerprint'])->doUpdate(['embedding'])->getQuerySql();
        self::assertStringContainsString('ON CONFLICT ("tenant_id", "collection", "chunk_id", "embedding_space_fingerprint")', $sql);
        self::assertStringContainsString('CAST(:embedding_0 AS vector)', $sql);
        self::assertStringNotContainsString('RETURNING', $sql);
        self::assertStringNotContainsString('"id"', $sql);
    }
}
