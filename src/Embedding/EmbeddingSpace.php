<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Embedding;

use InvalidArgumentException;

final readonly class EmbeddingSpace
{
    public string $fingerprint;
    /** @var array<string, mixed> */ public array $parameters;
    /** @param array<string, mixed> $parameters Semantic parameters only; never pass secrets or operational settings. */
    public function __construct(public string $provider, public string $model, public int $dimensions, public string $revision = '1', array $parameters = [])
    {
        if (trim($provider) === '' || trim($model) === '' || $dimensions < 1 || trim($revision) === '') {
            throw new InvalidArgumentException('Embedding space requires provider, model, dimensions, and revision.');
        }
        $normalized = self::normalizeMap($parameters);
        $this->parameters = $normalized;
        $this->fingerprint = hash('sha256', self::canonical(['provider' => $provider,'model' => $model,'dimensions' => $dimensions,'revision' => $revision,'parameters' => $normalized]));
    }
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }
    /**
     * @param array<mixed> $map
     * @return array<string,mixed>
     */
    private static function normalizeMap(array $map): array
    {
        if (array_is_list($map) && $map !== []) {
            throw new InvalidArgumentException('Embedding parameters root must be an associative map.');
        }
        $result = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Embedding parameter keys must be non-empty strings.');
            } $result[$key] = self::normalize($value);
        } ksort($result, SORT_STRING);
        return $result;
    }
    private static function normalize(mixed $value): mixed
    {
        if (is_null($value) || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Embedding parameters cannot contain non-finite floats.');
            } return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Embedding parameters must contain only serializable scalar and array values.');
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        return self::normalizeMap($value);
    }
    private static function canonical(mixed $value): string
    {
        if ($value === null) {
            return 'n';
        } if (is_bool($value)) {
            return $value ? 'b1' : 'b0';
        } if (is_int($value)) {
            return 'i' . $value;
        }
        if (is_float($value)) {
            return 'f' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if (is_string($value)) {
            return 's' . strlen($value) . ':' . $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (is_int($key) ? 'k'.$key : 'K'.strlen($key).':'.$key) . self::canonical($item);
            } return (array_is_list($value) ? 'l' : 'm') . count($value) . '{' . implode('', $parts) . '}';
        }
        throw new InvalidArgumentException('Unsupported canonical value.');
    }
}
