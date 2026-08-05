<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VersionSelectionPolicy;
use PHPUnit\Framework\TestCase;

final class LexicalSearchQueryTest extends TestCase
{
    public function testRejectsEmptyTenantId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LexicalSearchQuery('', 'ERR_PAYMENT_1047');
    }

    public function testRejectsEmptyTerms(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LexicalSearchQuery('tenant-a', '   ');
    }

    public function testCollectionIsOptionalAndNullByDefault(): void
    {
        $query = new LexicalSearchQuery('tenant-a', 'SKU-ABX-991');

        self::assertNull($query->collection);
    }

    public function testPolicyIsPreserved(): void
    {
        $policy = new RetrievalPolicy(limit: 7, maximumDistance: 0.8);

        $query = new LexicalSearchQuery(
            tenantId: 'tenant-a',
            terms: 'ContextPromptBuilder',
            policy: $policy,
        );

        self::assertSame($policy, $query->policy);
        self::assertSame(7, $query->policy->limit);
    }

    public function testVersionSelectionPolicyIsPreserved(): void
    {
        $versionSelection = VersionSelectionPolicy::validAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $query = new LexicalSearchQuery(
            tenantId: 'tenant-a',
            terms: 'v2.4.17',
            versionSelectionPolicy: $versionSelection,
        );

        self::assertSame($versionSelection, $query->versionSelectionPolicy);
        self::assertSame('valid-at', $query->versionSelectionPolicy?->mode);
    }
}
