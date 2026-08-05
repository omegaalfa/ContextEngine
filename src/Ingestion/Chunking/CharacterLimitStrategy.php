<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

use InvalidArgumentException;

final readonly class CharacterLimitStrategy implements ChunkingStrategy
{
    public function __construct(public int $limit = 1000)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Character limit must be greater than zero.');
        }
    }

    public function fingerprint(): string
    {
        return 'characters:' . $this->limit;
    }

    public function fits(string $content, int $blockCount): bool
    {
        return mb_strlen($content) <= $this->limit;
    }

    public function split(string $content): array
    {
        $parts = [];
        while (mb_strlen($content) > $this->limit) {
            $window = mb_substr($content, 0, $this->limit);
            $boundary = max((int) mb_strrpos($window, "\n"), (int) mb_strrpos($window, ' '));
            $boundary = $boundary > intdiv($this->limit, 2) ? $boundary : $this->limit;
            $parts[] = trim(mb_substr($content, 0, $boundary));
            $content = ltrim(mb_substr($content, $boundary));
        }
        if (trim($content) !== '') {
            $parts[] = trim($content);
        }

        return $parts;
    }
}
