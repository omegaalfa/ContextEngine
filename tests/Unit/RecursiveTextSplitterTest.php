<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\Support\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecursiveTextSplitterTest extends TestCase
{
    public function testRespectsSizeOverlapAndSemanticBoundaries(): void
    {
        $document = new Document('doc', 'tenant', "First paragraph has useful words.\n\nSecond paragraph also has useful words.\nThird line is here.");
        $chunks = iterator_to_array(new RecursiveTextSplitter(45, 8)->split($document));

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $position => $chunk) {
            self::assertLessThanOrEqual(45, mb_strlen($chunk->content));
            self::assertSame($position, $chunk->position);
            self::assertSame('tenant', $chunk->tenantId);
        }
        $this->assertExactCoverage($document->content, $chunks, 8);
    }

    #[DataProvider('contentProvider')]
    public function testEveryNormalizedCharacterIsCovered(string $content, int $chunkSize, int $overlap): void
    {
        $chunks = iterator_to_array(new RecursiveTextSplitter($chunkSize, $overlap)->split(new Document('doc', 'tenant', $content)));

        self::assertNotEmpty($chunks);
        $this->assertExactCoverage($content, $chunks, $overlap);
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual($chunkSize, mb_strlen($chunk->content));
            self::assertSame(1, preg_match('//u', $chunk->content), 'Chunks must remain valid UTF-8.');
        }
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function contentProvider(): iterable
    {
        yield 'long word' => [str_repeat('abcdefghij', 14), 23, 5];
        yield 'emoji' => [str_repeat('Olá 👩🏽‍💻 mundo 🌎! ', 12), 31, 7];
        yield 'crlf' => ["linha um\r\nlinha dois\r\n\r\nlinha três\rúltima linha", 20, 4];
        yield 'without overlap' => [str_repeat('abc def ', 15), 17, 0];
        yield 'without separators' => [str_repeat('界', 73), 16, 3];
        yield 'maximum overlap' => [str_repeat('0123456789', 4), 10, 9];
    }

    public function testAdjacentChunksContainTheExactConfiguredOverlap(): void
    {
        $overlap = 9;
        $chunks = iterator_to_array(new RecursiveTextSplitter(32, $overlap)->split(new Document('doc', 'tenant', str_repeat('alpha beta gamma ', 12))));

        for ($index = 1, $count = count($chunks); $index < $count; $index++) {
            self::assertSame(
                mb_substr($chunks[$index - 1]->content, -$overlap),
                mb_substr($chunks[$index]->content, 0, $overlap),
            );
        }
    }

    public function testIdsRemainDeterministic(): void
    {
        $document = new Document('d', 't', str_repeat('word ', 30));
        $splitter = new RecursiveTextSplitter(30, 5);

        self::assertSame(
            array_column(iterator_to_array($splitter->split($document)), 'id'),
            array_column(iterator_to_array($splitter->split($document)), 'id'),
        );
    }

    /** @param list<Chunk> $chunks */
    private function assertExactCoverage(string $original, array $chunks, int $overlap): void
    {
        $rebuilt = $chunks[0]->content;
        for ($index = 1, $count = count($chunks); $index < $count; $index++) {
            $rebuilt .= mb_substr($chunks[$index]->content, $overlap);
        }

        self::assertSame(new TextNormalizer()->normalize($original), $rebuilt);
    }
}
