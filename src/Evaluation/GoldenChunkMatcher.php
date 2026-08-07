<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;

final class GoldenChunkMatcher
{
    /**
     * @param list<Chunk> $chunks
     * @param non-empty-list<string> $terms
     * @return list<string>
     */
    public function ids(array $chunks, GoldenMatchMode $mode, array $terms): array
    {
        if (array_any($terms, static fn (string $term): bool => trim($term) === '')) {
            throw new InvalidArgumentException('Golden matching requires non-empty terms.');
        }
        $matches = array_filter($chunks, static function (Chunk $chunk) use ($mode, $terms): bool {
            $content = mb_strtolower($chunk->content);
            $checks = array_map(static fn (string $term): bool => str_contains($content, mb_strtolower($term)), $terms);
            return $mode === GoldenMatchMode::ALL ? !in_array(false, $checks, true) : in_array(true, $checks, true);
        });
        return array_values(array_map(static fn (Chunk $chunk): string => $chunk->id, $matches));
    }
}
