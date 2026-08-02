<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Support;

use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\InvalidEmbeddingException;
use Omegaalfa\ContextEngine\Exception\ProviderException;

final class EmbeddingResponseValidator
{
    /**
     * @param mixed $items
     * @return list<Embedding>
     */
    public static function orderedOpenAI(mixed $items, int $expectedCount, EmbeddingSpace $space): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) !== $expectedCount) {
            throw new ProviderException('OpenAI returned a different embedding batch size.');
        }

        $ordered = [];
        foreach ($items as $item) {
            $index = is_array($item) ? ($item['index'] ?? null) : null;
            $values = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (!is_int($index) || $index < 0 || $index >= $expectedCount || array_key_exists($index, $ordered)) {
                throw new ProviderException('OpenAI embedding response contains an invalid or duplicate index.');
            }
            $ordered[$index] = self::embedding($values, $space, 'OpenAI');
        }

        ksort($ordered);
        if (array_keys($ordered) !== range(0, $expectedCount - 1)) {
            throw new ProviderException('OpenAI embedding response does not cover every requested input.');
        }

        return array_values($ordered);
    }

    /**
     * @param mixed $items
     * @return list<Embedding>
     */
    public static function positional(mixed $items, int $expectedCount, EmbeddingSpace $space, string $provider): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) !== $expectedCount) {
            throw new ProviderException("{$provider} returned a different embedding batch size.");
        }

        return array_map(
            static fn (mixed $values): Embedding => self::embedding($values, $space, $provider),
            $items,
        );
    }

    private static function embedding(mixed $values, EmbeddingSpace $space, string $provider): Embedding
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new ProviderException("{$provider} returned an invalid embedding vector.");
        }

        try {
            return new Embedding($values, $space);
        } catch (InvalidEmbeddingException $exception) {
            throw new ProviderException("{$provider} returned an invalid embedding vector.", previous: $exception);
        }
    }
}
