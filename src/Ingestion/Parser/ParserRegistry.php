<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;

final readonly class ParserRegistry
{
    public function __construct(private StructuralNoisePolicy $noisePolicy = new StructuralNoisePolicy()) {}

    public function fingerprint(): string
    {
        return hash('sha256', 'parser-registry\0v2\0' . $this->noisePolicy->fingerprint());
    }

    public function parserFor(Document $document): DocumentParser
    {
        $format = strtolower((string) ($document->metadata['format'] ?? $document->metadata['type'] ?? pathinfo((string) ($document->metadata['source'] ?? ''), PATHINFO_EXTENSION)));

        return match ($format) {
            'md', 'markdown' => new MarkdownParser(),
            'html', 'htm' => new HtmlParser(),
            'json' => new JsonParser(),
            'xml' => new XmlParser(),
            'php' => new PhpParser(),
            'pdf' => new PdfParser($this->noisePolicy),
            default => new PlainTextParser(),
        };
    }
}
