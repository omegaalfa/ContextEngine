<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

require_once dirname(__DIR__, 2).'/examples/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use PHPUnit\Framework\TestCase;

final class OfflineLexicalSearchTest extends TestCase
{
    public function testUnknownTermsReturnNoLexicalResults(): void
    {
        $store = $this->store();

        self::assertSame([], $store->searchLexical(new LexicalSearchQuery(
            'tenant',
            'Qual é a complexidade da classe FooBarInexistente?',
            new RetrievalPolicy(limit: 20),
            'algorithms',
        )));
        self::assertSame([], $store->searchLexical(new LexicalSearchQuery(
            'tenant',
            'Explique o algoritmo XYZ-WESLEY-999.',
            new RetrievalPolicy(limit: 20),
            'algorithms',
        )));
    }

    public function testMatchingResultExposesPositiveLexicalScore(): void
    {
        $results = $this->store()->searchLexical(new LexicalSearchQuery(
            'tenant',
            'Como funciona Dijkstra?',
            new RetrievalPolicy(limit: 20),
            'algorithms',
        ));

        self::assertCount(1, $results);
        self::assertGreaterThan(0.0, $results[0]->lexicalScore);
    }

    private function store(): \DemoInMemoryStore
    {
        $store = new \DemoInMemoryStore();
        $embeddings = new \DemoEmbeddingProvider();
        $chunk = new Chunk(
            'dijkstra',
            'algorithms-document',
            'tenant',
            'Dijkstra encontra o menor caminho usando uma fila de prioridade.',
            0,
            collection: 'algorithms',
        );
        $store->storeBatch([new EmbeddedChunk($chunk, $embeddings->embed($chunk->content, 'tenant'))]);
        return $store;
    }
}
