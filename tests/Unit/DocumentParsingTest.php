<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\CodeBlockNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\HeadingNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\SectionNode;
use Omegaalfa\ContextEngine\Ingestion\Parser\HtmlParser;
use Omegaalfa\ContextEngine\Ingestion\Parser\JsonParser;
use Omegaalfa\ContextEngine\Ingestion\Parser\MarkdownParser;
use Omegaalfa\ContextEngine\Ingestion\Parser\PhpParser;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;
use PHPUnit\Framework\TestCase;

final class DocumentParsingTest extends TestCase
{
    public function testMarkdownPreservesHeadingsAndCodeBlocks(): void
    {
        $tree = new MarkdownParser()->parse($this->document("# API\n\nDescrição.\n\n```php\necho 1;\n```", 'markdown'));

        self::assertInstanceOf(HeadingNode::class, $tree->children[0]);
        self::assertInstanceOf(CodeBlockNode::class, $tree->children[2]);
        self::assertSame('php', $tree->children[2]->metadata()['language']);
    }

    public function testHtmlRecognizesStructuralBlocks(): void
    {
        $tree = new HtmlParser()->parse($this->document('<h1>Manual</h1><p>Texto</p><ul><li>A</li><li>B</li></ul>', 'html'));

        self::assertSame(['heading', 'paragraph', 'list'], array_map(static fn ($node): string => $node->type(), $tree->children));
    }

    public function testJsonPreservesObjectHierarchy(): void
    {
        $tree = new JsonParser()->parse($this->document('{"user":{"name":"Ada"}}', 'json'));

        self::assertInstanceOf(SectionNode::class, $tree->children[0]);
        self::assertInstanceOf(SectionNode::class, $tree->children[0]->children()[0]);
    }

    public function testPhpIdentifiesNaturalSymbols(): void
    {
        $tree = new PhpParser()->parse($this->document('<?php final class Service { public function run(): void {} }', 'php'));
        $types = array_column(array_map(static fn ($node): array => $node->metadata(), $tree->children), 'symbol_type');

        self::assertContains('class', $types);
        self::assertContains('function', $types);
    }

    public function testStructuralChunksRespectLimitAndMetadata(): void
    {
        $document = $this->document("# API\n\nPrimeiro parágrafo curto.\n\nSegundo parágrafo suficientemente longo para separar.", 'markdown');
        $chunks = iterator_to_array(new StructuralTextSplitter(new CharacterLimitStrategy(45))->split($document));

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $position => $chunk) {
            self::assertLessThanOrEqual(45, mb_strlen($chunk->content));
            self::assertSame($position, $chunk->position);
            self::assertArrayHasKey('block_type', $chunk->metadata);
            self::assertArrayHasKey('hierarchy_path', $chunk->metadata);
            self::assertArrayHasKey('parent_id', $chunk->metadata);
        }
    }

    private function document(string $content, string $format): Document
    {
        return new Document('doc', 'tenant', $content, ['format' => $format, 'version' => '1']);
    }
}
