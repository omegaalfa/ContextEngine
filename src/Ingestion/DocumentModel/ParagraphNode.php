<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

final readonly class ParagraphNode extends LeafNode
{
    public function type(): string
    {
        return 'paragraph';
    }
}
