<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Embedding\{Embedding,EmbeddingSpace};
use Omegaalfa\ContextEngine\Exception\InvalidEmbeddingException;
use Omegaalfa\ContextEngine\Ingestion\BatchEmbeddingResult;
use Omegaalfa\ContextEngine\Ingestion\BatchExecutionProgress;
use PHPUnit\Framework\TestCase;

final class EmbeddingSpaceTest extends TestCase
{
    public function testFingerprintIsCanonicalStableAndTypeSensitive(): void
    {
        $a = new EmbeddingSpace('p', 'm', 2, 'r', ['nested' => ['b' => 2,'a' => 1],'flag' => true]);
        $b = new EmbeddingSpace('p', 'm', 2, 'r', ['flag' => true,'nested' => ['a' => 1,'b' => 2]]);
        self::assertSame($a->fingerprint(), $b->fingerprint());
        self::assertNotSame($a->fingerprint(), new EmbeddingSpace('p', 'm', 2, 'r', ['nested' => ['a' => 1,'b' => '2'],'flag' => true])->fingerprint());
        self::assertNotSame($a->fingerprint(), new EmbeddingSpace('p', 'm', 2, 'r2', ['nested' => ['a' => 1,'b' => 2],'flag' => true])->fingerprint());
    }
    public function testBatchCannotMixSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BatchEmbeddingResult(0, [new Chunk('c1', 'd', 't', 'x', 0),new Chunk('c2', 'd', 't', 'y', 1)], [new Embedding([1], new EmbeddingSpace('p', 'm', 1)),new Embedding([1], new EmbeddingSpace('q', 'm', 1))], new BatchExecutionProgress(1, 1, 1, 0, 2));
    }

    public function testEmbeddingRejectsDimensionMismatch(): void
    {
        $this->expectException(InvalidEmbeddingException::class);
        new Embedding([1], new EmbeddingSpace('p', 'm', 2));
    }
}
