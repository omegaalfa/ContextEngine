<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VectorStoreDeletionTest extends TestCase
{
    /** @param callable(): object $factory */
    #[DataProvider('invalidScopeProvider')]
    public function testDeletionScopesRejectEmptyValues(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidScopeProvider(): iterable
    {
        $space = new EmbeddingSpace('provider', 'model', 1);
        yield 'chunk tenant' => [static fn (): object => new ChunkDeleteQuery('', 'docs', 'chunk', $space)];
        yield 'chunk collection' => [static fn (): object => new ChunkDeleteQuery('tenant', ' ', 'chunk', $space)];
        yield 'chunk id' => [static fn (): object => new ChunkDeleteQuery('tenant', 'docs', '', $space)];
        yield 'document id' => [static fn (): object => new DocumentDeleteQuery('tenant', 'docs', '')];
        yield 'collection tenant' => [static fn (): object => new CollectionDeleteQuery('', 'docs')];
        yield 'collection name' => [static fn (): object => new CollectionDeleteQuery('tenant', '')];
    }

    public function testDocumentSpaceIsExplicitlyOptional(): void
    {
        $allSpaces = new DocumentDeleteQuery('tenant', 'docs', 'document');
        $oneSpace = new DocumentDeleteQuery('tenant', 'docs', 'document', new EmbeddingSpace('provider', 'model', 1));

        self::assertNull($allSpaces->space);
        self::assertNotNull($oneSpace->space);
    }
}
