<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Loader;

use Generator;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Document\Document;
use RuntimeException;

final readonly class TextFileLoader implements DocumentLoader
{
    /**
     * @param string $path
     * @param string $tenantId
     * @param string $collection
     * @param string $status
     * @param TextFileGranularity $granularity
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        private string              $path,
        private string              $tenantId,
        private string              $collection = 'default',
        private string              $status = 'active',
        private TextFileGranularity $granularity = TextFileGranularity::PARAGRAPH,
        private array               $metadata = [],
    ) {}

    /**
     * @return Generator
     */
    public function load(): Generator
    {
        $canonicalPath = realpath($this->path);

        if (
            $canonicalPath === false
            || !is_file($canonicalPath)
            || !is_readable($canonicalPath)
        ) {
            throw new RuntimeException(
                "Unable to open {$this->path}.",
            );
        }

        if ($this->granularity === TextFileGranularity::WHOLE_FILE) {
            yield $this->wholeFile($canonicalPath);

            return;
        }

        yield from $this->paragraphs($canonicalPath);
    }

    /**
     * @param string $canonicalPath
     * @return Document
     */
    private function wholeFile(string $canonicalPath): Document
    {
        $content = file_get_contents($canonicalPath);

        if ($content === false) {
            throw new RuntimeException(
                "Unable to read {$canonicalPath}.",
            );
        }

        $content = trim($content);

        if ($content === '') {
            throw new RuntimeException(
                "Text file is empty: {$canonicalPath}.",
            );
        }

        return $this->document(
            content: $content,
            identifier: 'whole-file',
            canonicalPath: $canonicalPath,
        );
    }

    /**
     * @return Generator<int, Document>
     */
    private function paragraphs(string $canonicalPath): Generator
    {
        $handle = @fopen($canonicalPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                "Unable to open {$canonicalPath}.",
            );
        }

        try {
            $buffer = '';
            $index = 0;

            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '' && trim($buffer) !== '') {
                    yield $this->document(
                        content: $buffer,
                        identifier: "paragraph:{$index}",
                        canonicalPath: $canonicalPath,
                    );

                    $index++;
                    $buffer = '';

                    continue;
                }

                $buffer .= $line;
            }

            if (trim($buffer) !== '') {
                yield $this->document(
                    content: $buffer,
                    identifier: "paragraph:{$index}",
                    canonicalPath: $canonicalPath,
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string $content
     * @param string $identifier
     * @param string $canonicalPath
     * @return Document
     */
    private function document(string $content, string $identifier, string $canonicalPath): Document
    {
        $content = trim($content);

        return new Document(
            id: hash(
                'sha256',
                $this->tenantId
                . "\0"
                . $canonicalPath
                . "\0"
                . $identifier
                . "\0"
                . hash('sha256', $content),
            ),
            tenantId: $this->tenantId,
            content: $content,
            metadata: array_merge(
                [
                    'source' => $canonicalPath,
                    'type' => 'text',
                    'granularity' => $this->granularity->name,
                ],
                $this->metadata,
            ),
            collection: $this->collection,
            status: $this->status,
        );
    }
}
