<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Retrieval\ReciprocalRankFusion;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use PHPUnit\Framework\TestCase;

final class WeightedRrfTest extends TestCase
{
    public function testWeightsCanReduceInfluenceOfWeakRanking(): void
    {
        $vectorWinner = $this->searchResult('vector-winner', 0.1);
        $lexicalWinner = $this->searchResult('lexical-winner', 0.5, 2.0);
        $rankings = [
            'vector' => [$vectorWinner, $lexicalWinner],
            'lexical' => [$lexicalWinner, $vectorWinner],
        ];

        $fused = new ReciprocalRankFusion()->fuse(
            $rankings,
            2,
            ['vector' => 0.5, 'lexical' => 1.0],
        );

        self::assertSame('lexical-winner', $fused[0]->chunk->id);
        self::assertGreaterThan($fused[1]->fusionScore, $fused[0]->fusionScore);
    }

    public function testRejectsInvalidWeight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReciprocalRankFusion()->fuse(['vector' => [$this->searchResult('chunk', 0.1)]], 1, ['vector' => -1.0]);
    }

    private function searchResult(string $id, float $distance, ?float $lexicalScore = null): VectorSearchResult
    {
        return new VectorSearchResult(
            new Chunk($id, 'document', 'tenant', 'content', 0),
            $distance,
            lexicalScore: $lexicalScore,
        );
    }
}
