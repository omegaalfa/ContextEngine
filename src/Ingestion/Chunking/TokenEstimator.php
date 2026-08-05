<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

interface TokenEstimator
{
    public function estimate(string $content): int;

    public function fingerprint(): string;
}
