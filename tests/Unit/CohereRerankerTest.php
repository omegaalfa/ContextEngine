<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Provider\Cohere\CohereReranker;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CohereRerankerTest extends TestCase
{
    public function testMapsCohereIndexesAndPreservesPreviousScores(): void
    {
        $reranker = new CohereReranker('key', client: new AsyncHttpClient());
        $candidates = [
            new VectorSearchResult(new Chunk('first', 'doc', 'tenant', 'Primeiro', 0), 0.2, fusionScore: 0.03, lexicalScore: 0.8),
            new VectorSearchResult(new Chunk('second', 'doc', 'tenant', 'Segundo', 1), 0.4, fusionScore: 0.02, lexicalScore: 0.5),
        ];
        $method = new ReflectionMethod($reranker, 'ordered');
        $ranked = $method->invoke($reranker, $candidates, [
            ['index' => 1, 'relevance_score' => 0.95],
            ['index' => 0, 'relevance_score' => 0.25],
        ]);

        self::assertSame(['second', 'first'], array_map(static fn ($result): string => $result->chunk->id, $ranked));
        self::assertSame(0.4, $ranked[0]->distance);
        self::assertSame(0.5, $ranked[0]->lexicalScore);
        self::assertSame(0.02, $ranked[0]->fusionScore);
        self::assertSame(0.95, $ranked[0]->rerankerScore);
        self::assertSame('cohere', $reranker->provider());
        self::assertSame('rerank-v4.0-pro', $reranker->model());
    }

    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConfiguration(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'empty key' => [static fn (): CohereReranker => new CohereReranker('')];
        yield 'empty model' => [static fn (): CohereReranker => new CohereReranker('key', '')];
        yield 'invalid base URL' => [static fn (): CohereReranker => new CohereReranker('key', baseUrl: 'ftp://example.com')];
        yield 'zero timeout' => [static fn (): CohereReranker => new CohereReranker('key', timeoutSeconds: 0)];
    }
}
