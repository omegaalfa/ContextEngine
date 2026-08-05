<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\HighLevel;

final readonly class RedisConfig
{
    public function __construct(
        public ?string $host = null,
        public ?int $port = null,
        public ?string $password = null,
    ) {}
}
