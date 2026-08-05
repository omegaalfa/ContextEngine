<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

abstract readonly class LeafNode implements Node
{
    /** @param array<string, scalar|null> $metadataValues */
    public function __construct(private string $value, private array $metadataValues = []) {}

    public function content(): string
    {
        return $this->value;
    }

    public function children(): array
    {
        return [];
    }

    public function metadata(): array
    {
        return $this->metadataValues;
    }
}
