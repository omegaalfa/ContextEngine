<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class VersionSelectionPolicy
{
    private function __construct(
        public string $mode,
        public ?DateTimeImmutable $asOf = null,
    ) {
        if (!in_array($mode, ['active', 'valid-at', 'all-versions'], true)) {
            throw new InvalidArgumentException('Unsupported version selection mode.');
        }
        if ($mode === 'valid-at' && $asOf === null) {
            throw new InvalidArgumentException('A valid-at policy requires a point in time.');
        }
    }

    public static function active(): self
    {
        return new self('active');
    }

    public static function validAt(DateTimeImmutable $asOf): self
    {
        return new self('valid-at', $asOf);
    }

    public static function allVersions(): self
    {
        return new self('all-versions');
    }
}
