<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersionStatus;
use Omegaalfa\ContextEngine\Ingestion\VersionConflictDetector;
use PHPUnit\Framework\TestCase;

final class VersionConflictDetectorTest extends TestCase
{
    public function testDetectsOverlappingValidityWindowsForSameDocument(): void
    {
        $space = new EmbeddingSpace('provider', 'model', 3, 'a');
        $base = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $first = new DocumentVersion(
            new Document('doc', 'tenant', 'content', collection: 'docs'),
            $space,
            'splitter-a',
            status: DocumentVersionStatus::ACTIVE,
            validFrom: $base,
            validUntil: $base->modify('+2 months'),
            revision: 1,
        );
        $second = new DocumentVersion(
            new Document('doc', 'tenant', 'content-2', collection: 'docs'),
            $space,
            'splitter-a',
            status: DocumentVersionStatus::ACTIVE,
            validFrom: $base->modify('+1 month'),
            validUntil: $base->modify('+3 months'),
            revision: 2,
        );

        $conflicts = new VersionConflictDetector()->detect([$first, $second]);

        self::assertCount(1, $conflicts);
        self::assertSame('overlapping-validity-window', $conflicts[0]->reason);
        self::assertSame('doc', $conflicts[0]->documentId);
    }
}
