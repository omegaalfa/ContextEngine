<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;

final readonly class PlainTextParser implements DocumentParser
{
    public function parse(Document $document): DocumentNode
    {
        $parts = preg_split('/\R\s*\R/u', trim($document->content)) ?: [];
        $nodes = array_map(static fn (string $part): ParagraphNode => new ParagraphNode(trim($part)), array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));

        return new DocumentNode($nodes, $document->metadata);
    }
}
