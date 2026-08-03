<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Splitter;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\TextSplitter;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Support\TextNormalizer;

final readonly class RecursiveTextSplitter implements TextSplitter
{
    /**
     * @param int $chunkSize
     * @param int $overlap
     * @param TextNormalizer $normalizer
     */
    public function __construct(
        public int             $chunkSize = 1000,
        public int             $overlap = 150,
        private TextNormalizer $normalizer = new TextNormalizer(),
    ) {
        if ($chunkSize < 1 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new InvalidArgumentException('Overlap must be non-negative and smaller than chunk size.');
        }
    }

    /**
     * @return string
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", ['recursive-text-splitter', '2', (string)$this->chunkSize, (string)$this->overlap]));
    }

    /** @return iterable<Chunk> */
    public function split(Document $document): iterable
    {
        $text = $this->normalizer->normalize($document->content);
        $length = mb_strlen($text);
        $start = 0;
        $position = 0;

        while ($start < $length) {
            $hardEnd = min($start + $this->chunkSize, $length);
            $end = $hardEnd === $length ? $length : $this->semanticEnd($text, $start, $hardEnd);
            $content = mb_substr($text, $start, $end - $start);

            if (trim($content) !== '') {
                $id = hash('sha256', $document->tenantId . "\0" . $document->id . "\0" . $position . "\0" . $content);
                yield new Chunk(
                    $id,
                    $document->id,
                    $document->tenantId,
                    $content,
                    $position,
                    $document->metadata,
                    $document->collection,
                    $document->status,
                );
                $position++;
            }

            if ($end === $length) {
                break;
            }
            $start = $end - $this->overlap;
        }
    }

    /**
     * @param string $text
     * @param int $start
     * @param int $hardEnd
     * @return int
     */
    private function semanticEnd(string $text, int $start, int $hardEnd): int
    {
        $window = mb_substr($text, $start, $hardEnd - $start);
        $minimum = max($this->overlap + 1, intdiv($this->chunkSize, 2));
        $patterns = ["/\n{2,}/u", "/\n/u", '/(?<=[.!?])\s+/u', '/\s+/u'];

        foreach ($patterns as $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            for ($index = count($matches[0]) - 1; $index >= 0; $index--) {
                [$separator, $byteOffset] = $matches[0][$index];
                $boundary = mb_strlen(substr($window, 0, $byteOffset + strlen($separator)));
                if ($boundary >= $minimum) {
                    return $start + $boundary;
                }
            }
        }

        return $hardEnd;
    }
}
