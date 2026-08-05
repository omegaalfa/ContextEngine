<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

final readonly class DocumentNode
{
    /**
     * @param list<Node> $children
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(public array $children, public array $metadata = []) {}
}
