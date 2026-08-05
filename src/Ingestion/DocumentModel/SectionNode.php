<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

final readonly class SectionNode implements Node
{
    /**
     * @param list<Node> $nodes
     * @param array<string, scalar|null> $metadataValues
     */
    public function __construct(private string $title, private array $nodes = [], private array $metadataValues = []) {}

    public function type(): string
    {
        return 'section';
    }

    public function content(): string
    {
        return $this->title;
    }

    public function children(): array
    {
        return $this->nodes;
    }

    public function metadata(): array
    {
        return $this->metadataValues;
    }
}
