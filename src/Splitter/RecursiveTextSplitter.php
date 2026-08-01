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
        private TextNormalizer $normalizer = new TextNormalizer()
    )
    {
        if ($chunkSize < 1 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new InvalidArgumentException('Overlap must be non-negative and smaller than chunk size.');
        }
    }

    /**
     * @param Document $document
     * @return iterable<Chunk>
     */
    public function split(Document $document): iterable
    {
        $parts = $this->partition($this->normalizer->normalize($document->content), 0);
        $previous = '';
        foreach ($parts as $position => $part) {
            $prefix = $position === 0 ? '' : $this->overlapSuffix($previous, $this->overlap);
            $content = trim($prefix === '' ? $part : $prefix . "\n" . $part);
            if (mb_strlen($content) > $this->chunkSize) {
                $content = mb_substr($content, 0, $this->chunkSize);
            }
            $id = hash('sha256', $document->tenantId . "\0" . $document->id . "\0" . $position . "\0" . $content);
            yield new Chunk(
                $id,
                $document->id,
                $document->tenantId,
                $content,
                $position,
                $document->metadata,
                $document->collection,
                $document->status
            );
            $previous = $part;
        }
    }

    /**
     * @param string $text
     * @param int $level
     * @return list<string>
     */
    private function partition(string $text, int $level): array
    {
        if (mb_strlen($text) <= $this->chunkSize) {
            return [$text];
        }
        $patterns = ["/\n{2,}/u", "/\n/u", '/(?<=[.!?])\s+/u', '/\s+/u', '//u'];
        $pieces = preg_split($patterns[min($level, 4)], $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        if (count($pieces) === 1) {
            return $level < 4 ? $this->partition($text, $level + 1) : $this->characters($text);
        }
        $separator = $level < 2 ? "\n" : ' ';
        $chunks = [];
        $buffer = '';
        foreach ($pieces as $piece) {
            if (mb_strlen($piece) > $this->chunkSize) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                array_push($chunks, ...$this->partition($piece, $level + 1));
                continue;
            }
            $candidate = $buffer === '' ? $piece : $buffer . $separator . $piece;
            if (mb_strlen($candidate) <= $this->chunkSize) {
                $buffer = $candidate;
                continue;
            }
            $chunks[] = $buffer;
            $buffer = $piece;
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        return $chunks;
    }

    /**
     * @param string $text
     * @return list<string>
     */
    private function characters(string $text): array
    {
        $out = [];
        for ($i = 0, $n = mb_strlen($text); $i < $n; $i += $this->chunkSize) {
            $out[] = mb_substr($text, $i, $this->chunkSize);
        }
        return $out;
    }

    /**
     * @param string $text
     * @param int $length
     * @return string
     */
    private function overlapSuffix(string $text, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        $suffix = mb_substr($text, -$length);
        $space = mb_strpos($suffix, ' ');
        return trim($space === false ? $suffix : mb_substr($suffix, $space + 1));
    }
}
