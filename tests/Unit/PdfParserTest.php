<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\Parser\PdfParser;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;
use PHPUnit\Framework\TestCase;

final class PdfParserTest extends TestCase
{
    public function testPagesAreMetadataInsteadOfChunkBoundaries(): void
    {
        $document = new Document(
            'book',
            'tenant',
            "[[CONTEXT_ENGINE_PAGE:10]]\n\nCapítulo 1\n\nPrimeiro parágrafo relacionado.\n\n"
            . "[[CONTEXT_ENGINE_PAGE:11]]\n\nSegundo parágrafo da mesma seção.",
            ['format' => 'pdf'],
        );

        $tree = (new PdfParser())->parse($document);
        $chunks = iterator_to_array((new StructuralTextSplitter(new CharacterLimitStrategy(500)))->split($document));

        self::assertCount(3, $tree->children);
        self::assertCount(1, $chunks);
        self::assertSame(10, $chunks[0]->metadata['page_start']);
        self::assertSame(11, $chunks[0]->metadata['page_end']);
        self::assertStringContainsString('Primeiro parágrafo', $chunks[0]->content);
        self::assertStringContainsString('Segundo parágrafo', $chunks[0]->content);
    }
}
