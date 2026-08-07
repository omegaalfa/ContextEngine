<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\CodeBlockNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DiagramTextNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\FigureTextNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\HeadingNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ListNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;
use Omegaalfa\ContextEngine\Ingestion\Parser\ParserRegistry;
use Omegaalfa\ContextEngine\Ingestion\Parser\PdfParser;
use Omegaalfa\ContextEngine\Ingestion\Parser\StructuralNoiseKind;
use Omegaalfa\ContextEngine\Ingestion\Parser\StructuralNoisePolicy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuralNoisePolicyTest extends TestCase
{
    /** @return iterable<string, array{string, StructuralNoiseKind}> */
    public static function noiseBlocks(): iterable
    {
        yield 'simple diagram' => ['A    B     C', StructuralNoiseKind::DIAGRAM_TEXT];
        yield 'numeric block' => ['0   0   0   0', StructuralNoiseKind::DIAGRAM_TEXT];
        yield 'numeric sequence' => ['1 2 3 4 5', StructuralNoiseKind::DIAGRAM_TEXT];
        yield 'isolated letters' => ["A\nB\nC\nD", StructuralNoiseKind::FIGURE_TEXT];
        yield 'malformed table' => ["A      B      C\n0      0      0", StructuralNoiseKind::DIAGRAM_TEXT];
    }

    #[DataProvider('noiseBlocks')]
    public function testClassifiesStructuralNoise(string $content, StructuralNoiseKind $expected): void
    {
        $decision = new StructuralNoisePolicy()->classify($content);

        self::assertSame($expected, $decision->kind);
        self::assertTrue($decision->excludeFromRetrieval);
        self::assertGreaterThanOrEqual(0.85, $decision->confidence);
    }

    /** @return iterable<string, array{string}> */
    public static function legitimateText(): iterable
    {
        yield 'heading' => ['Introdução'];
        yield 'short paragraph' => ['Nota.'];
        yield 'natural sentence' => ['Busca binária reduz o espaço pela metade.'];
        yield 'valid list' => ["- Primeiro item\n- Segundo item"];
        yield 'source code' => ['function quickSort(array &$items): void {}'];
    }

    #[DataProvider('legitimateText')]
    public function testPreservesLegitimateContent(string $content): void
    {
        self::assertSame(StructuralNoiseKind::CONTENT, new StructuralNoisePolicy()->classify($content)->kind);
    }

    public function testFilteringCanBeDisabled(): void
    {
        $policy = new StructuralNoisePolicy(enabled: false);

        self::assertSame(StructuralNoiseKind::CONTENT, $policy->classify('0 0 0 0')->kind);
    }

    public function testParserPreservesNoiseForDiagnosticsButChunkerExcludesIt(): void
    {
        $document = $this->pdf("Introdução\n\nTexto curto, mas válido.\n\nA    B     C\n\n0 0 0 0");
        $tree = new PdfParser()->parse($document);
        $chunks = iterator_to_array(new StructuralTextSplitter(
            new CharacterLimitStrategy(500),
            new ParserRegistry(),
        )->split($document));

        self::assertInstanceOf(HeadingNode::class, $tree->children[0]);
        self::assertInstanceOf(ParagraphNode::class, $tree->children[1]);
        self::assertInstanceOf(DiagramTextNode::class, $tree->children[2]);
        self::assertInstanceOf(DiagramTextNode::class, $tree->children[3]);
        self::assertTrue($tree->children[2]->metadata()['structural_noise']);
        self::assertSame(2, $tree->children[2]->metadata()['source_position']);
        self::assertCount(1, $chunks);
        self::assertStringNotContainsString('A    B', $chunks[0]->content);
        self::assertStringNotContainsString('0 0 0 0', $chunks[0]->content);
    }

    public function testNoiseOnlyDocumentProducesNoChunks(): void
    {
        $chunks = iterator_to_array(new StructuralTextSplitter(
            new CharacterLimitStrategy(500),
            new ParserRegistry(),
        )->split($this->pdf("A\nB\nC\nD\n\n0 0 0 0")));

        self::assertSame([], $chunks);
    }

    public function testCodeAndListAreProtectedDuringPdfParsing(): void
    {
        $tree = new PdfParser()->parse($this->pdf(
            "function quickSort(array &\$items): void {}\n\n- Primeiro item\n- Segundo item",
        ));

        self::assertInstanceOf(CodeBlockNode::class, $tree->children[0]);
        self::assertInstanceOf(ListNode::class, $tree->children[1]);
    }

    private function pdf(string $content): Document
    {
        return new Document(
            'pdf',
            'tenant',
            "[[CONTEXT_ENGINE_PAGE:1]]\n\n{$content}",
            ['format' => 'pdf'],
        );
    }
}
