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
     */
    public function __construct(private string $path, private string $tenantId)
    {
    }

    /**
     * @return Generator
     */
    public function load(): Generator
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$this->path}.");
        }
        try {
            $buffer = '';
            $index = 0;
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '' && trim($buffer) !== '') {
                    yield $this->document($buffer, $index++);
                    $buffer = '';
                    continue;
                }
                $buffer .= $line;
            }
            if (trim($buffer) !== '') {
                yield $this->document($buffer, $index);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string $content
     * @param int $index
     * @return Document
     */
    private function document(string $content, int $index): Document
    {
        return new Document(hash('sha256', $this->path . ':' . $index), $this->tenantId, $content, ['source' => $this->path]);
    }
}
