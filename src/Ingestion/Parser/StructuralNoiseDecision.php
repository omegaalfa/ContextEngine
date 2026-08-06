<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

final readonly class StructuralNoiseDecision
{
    public function __construct(
        public StructuralNoiseKind $kind,
        public bool $excludeFromRetrieval = false,
        public string $reason = 'natural_language',
        public float $confidence = 0.0,
    ) {}

    public function isNoise(): bool
    {
        return $this->kind !== StructuralNoiseKind::CONTENT;
    }
}
