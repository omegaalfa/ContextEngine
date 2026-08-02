<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use PHPUnit\Framework\TestCase;

final class DocumentVersionTest extends TestCase
{
    public function testIdentityIsStableAndMetadataOrderDoesNotMatter(): void
    {
        $space = new EmbeddingSpace('provider', 'model', 3, 'revision');
        $a = new DocumentVersion(new Document('doc', 'tenant', 'content', ['b' => 2, 'a' => 1], 'docs'), $space, 'splitter-a');
        $b = new DocumentVersion(new Document('doc', 'tenant', 'content', ['a' => 1, 'b' => 2], 'docs'), $space, 'splitter-a');
        self::assertSame($a->id, $b->id);
    }

    public function testSemanticDocumentOrSpaceChangeCreatesAnotherVersion(): void
    {
        $document = new Document('doc', 'tenant', 'content', collection: 'docs');
        $base = new DocumentVersion($document, new EmbeddingSpace('provider', 'model', 3, 'a'), 'splitter-a');
        $contentChanged = new DocumentVersion(new Document('doc', 'tenant', 'changed', collection: 'docs'), new EmbeddingSpace('provider', 'model', 3, 'a'), 'splitter-a');
        $spaceChanged = new DocumentVersion($document, new EmbeddingSpace('provider', 'model', 3, 'b'), 'splitter-a');
        $splitterChanged = new DocumentVersion($document, new EmbeddingSpace('provider', 'model', 3, 'a'), 'splitter-b');
        self::assertNotSame($base->id, $contentChanged->id);
        self::assertNotSame($base->id, $spaceChanged->id);
        self::assertNotSame($base->id, $splitterChanged->id);
    }
}
