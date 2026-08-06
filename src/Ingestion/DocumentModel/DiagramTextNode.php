<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

final readonly class DiagramTextNode extends LeafNode
{
    public function type(): string
    {
        return 'diagram_text';
    }
}
