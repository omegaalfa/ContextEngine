<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;

final readonly class ParserRegistry
{
    public function parserFor(Document $document): DocumentParser
    {
        $format = strtolower((string) ($document->metadata['format'] ?? $document->metadata['type'] ?? pathinfo((string) ($document->metadata['source'] ?? ''), PATHINFO_EXTENSION)));

        return match ($format) {
            'md', 'markdown' => new MarkdownParser(),
            'html', 'htm' => new HtmlParser(),
            'json' => new JsonParser(),
            'xml' => new XmlParser(),
            'php' => new PhpParser(),
            'pdf' => new PdfParser(),
            default => new PlainTextParser(),
        };
    }
}
