<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

final readonly class FigureTextNode extends LeafNode
{
    public function type(): string
    {
        return 'figure_text';
    }
}
