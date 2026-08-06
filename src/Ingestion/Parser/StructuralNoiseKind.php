<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

enum StructuralNoiseKind: string
{
    case CONTENT = 'content';
    case FIGURE_TEXT = 'figure_text';
    case DIAGRAM_TEXT = 'diagram_text';
    case UNKNOWN = 'unknown';
}
