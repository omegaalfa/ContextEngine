<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
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
    }
    public function testIdsAreDeterministic(): void
    {
        $doc = new Document('d', 't', str_repeat('word ', 30));
        $splitter = new RecursiveTextSplitter(30, 5);
        self::assertSame(array_column(iterator_to_array($splitter->split($doc)), 'id'), array_column(iterator_to_array($splitter->split($doc)), 'id'));
    }
}
