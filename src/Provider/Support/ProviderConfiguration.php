<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Support;

use InvalidArgumentException;

final class ProviderConfiguration
{
    public static function nonEmpty(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("{$name} cannot be empty.");
        }

        return $value;
    }

    public static function positiveDimensions(int $dimensions): int
    {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Embedding dimensions must be greater than zero.');
        }

        return $dimensions;
    }

    public static function baseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $parts = parse_url($baseUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Provider base URL must be an absolute HTTP or HTTPS URL without credentials, query, or fragment.');
        }

        return rtrim($baseUrl, '/');
    }
}
