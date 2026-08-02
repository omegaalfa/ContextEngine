<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Loader\Pdf;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Contract\PdfTextExtractor;
use Omegaalfa\ContextEngine\Document\Document;

final readonly class PdfDocumentLoader implements DocumentLoader
{
    private const PAGE_MARKER_PREFIX = '[[CONTEXT_ENGINE_PAGE:';

    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        private string $path,
        private string $tenantId,
        private PdfTextExtractor $extractor,
        private string $collection = 'default',
        private string $status = 'active',
        private int $pagesPerDocument = 3,
        private array $metadata = [],
    ) {
        if (trim($path) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('PDF path and tenant id cannot be empty.');
        }
        if (trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('PDF collection and status cannot be empty.');
        }
        if ($pagesPerDocument < 1) {
            throw new InvalidArgumentException('Pages per PDF document must be greater than zero.');
        }
    }

    public function load(): iterable
    {
        $window = [];
        foreach ($this->extractor->extract($this->path) as $page) {
            if (trim($page->text) === '') {
                continue;
            }
            $window[] = $page;
            if (count($window) < $this->pagesPerDocument) {
                continue;
            }

            yield $this->document($window);
            $window = [];
        }

        if ($window !== []) {
            yield $this->document($window);
        }
    }

    /**
     * @param non-empty-list<ExtractedPdfPage> $pages
     */
    private function document(array $pages): Document
    {
        $first = $pages[0];
        $last = $pages[array_key_last($pages)];
        $canonicalPath = realpath($this->path) ?: $this->path;
        $pageNumbers = array_map(static fn (ExtractedPdfPage $page): string => (string) $page->number, $pages);
        $methods = array_values(array_unique(array_map(
            static fn (ExtractedPdfPage $page): string => $page->method,
            $pages,
        )));
        $content = implode("\n\n", array_map(
            static fn (ExtractedPdfPage $page): string => sprintf(
                '%s%d]]%s%s',
                self::PAGE_MARKER_PREFIX,
                $page->number,
                "\n\n",
                str_replace(self::PAGE_MARKER_PREFIX, '[[SOURCE_PAGE:', trim($page->text)),
            ),
            $pages,
        ));

        return new Document(
            id: hash('sha256', implode("\0", [
                $this->tenantId,
                $canonicalPath,
                (string) $first->number,
                (string) $last->number,
            ])),
            tenantId: $this->tenantId,
            content: $content,
            metadata: array_merge($this->metadata, [
                'source' => $canonicalPath,
                'format' => 'pdf',
                'page_start' => $first->number,
                'page_end' => $last->number,
                'page_numbers' => implode(',', $pageNumbers),
                'extraction_method' => implode(',', $methods),
            ]),
            collection: $this->collection,
            status: $this->status,
        );
    }
}
