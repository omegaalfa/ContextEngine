<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersionIdentity;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersionStatus;
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

    public function testStatusAndTemporalBoundsDriveVersionValidity(): void
    {
        $base = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $version = new DocumentVersion(
            new Document('doc', 'tenant', 'content', collection: 'docs'),
            new EmbeddingSpace('provider', 'model', 3, 'a'),
            'splitter-a',
            status: DocumentVersionStatus::ACTIVE,
            validFrom: $base,
            validUntil: $base->modify('+1 month'),
            revision: 3,
            supersedesVersionId: 'prev-version',
        );

        self::assertSame(DocumentVersionStatus::ACTIVE, $version->status);
        self::assertSame(3, $version->revision);
        self::assertSame('prev-version', $version->supersedesVersionId);
        self::assertInstanceOf(DocumentVersionIdentity::class, $version->identity);
        self::assertSame('doc', $version->identity->documentId);
        self::assertSame($version->id, $version->identity->versionId);
        self::assertSame(3, $version->identity->revision);
        self::assertTrue($version->isValidAt($base->modify('+10 days')));
        self::assertFalse($version->isValidAt($base->modify('+1 month 1 day')));
    }
}
