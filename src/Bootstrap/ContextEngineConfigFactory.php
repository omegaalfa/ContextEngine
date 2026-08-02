<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Bootstrap\Config\DatabaseConfig;
use Omegaalfa\ContextEngine\Bootstrap\Config\OllamaConfig;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

final class ContextEngineConfigFactory
{
    /**
     *
     */
    private function __construct()
    {
    }

    /** Reads an environment already populated by the process or EnvLoader. */
    public static function fromEnvironment(): ContextEngineConfig
    {
        return new ContextEngineConfig(
            database: new DatabaseConfig(
                host: self::string('CONTEXT_ENGINE_PGVECTOR_HOST', '127.0.0.1'),
                database: self::string('CONTEXT_ENGINE_PGVECTOR_DATABASE', 'context_engine'),
                port: self::integer('CONTEXT_ENGINE_PGVECTOR_PORT', 54339),
                username: self::string('CONTEXT_ENGINE_PGVECTOR_USERNAME', 'context_engine'),
                password: self::string('CONTEXT_ENGINE_PGVECTOR_PASSWORD', 'context_engine'),
            ),
            ollama: new OllamaConfig(
                model: self::string('CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
                dimensions: self::integer('CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS', 1024),
                baseUrl: self::string('CONTEXT_ENGINE_OLLAMA_URL', 'http://127.0.0.1:11434'),
            ),
            collection: self::string('CONTEXT_ENGINE_COLLECTION', 'default'),
            status: self::string('CONTEXT_ENGINE_STATUS', 'active'),
            batchSize: self::integer('CONTEXT_ENGINE_BATCH_SIZE', 32),
            concurrency: self::integer('CONTEXT_ENGINE_CONCURRENCY', 4),
            chunkSize: self::integer('CONTEXT_ENGINE_CHUNK_SIZE', 1_000),
            overlap: self::integer('CONTEXT_ENGINE_CHUNK_OVERLAP', 150),
            retrievalLimit: self::integer('CONTEXT_ENGINE_RETRIEVAL_LIMIT', 5),
            retrievalMetric: self::metric('CONTEXT_ENGINE_RETRIEVAL_METRIC', VectorMetric::COSINE),
            maximumDistance: self::nullableFloat('CONTEXT_ENGINE_MAXIMUM_DISTANCE', 0.45),
        );
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    private static function string(string $key, string $default): string
    {
        $value = trim(EnvLoader::get($key) ?? '');

        return $value === '' ? $default : $value;
    }

    /**
     * @param string $key
     * @param int $default
     * @return int
     */
    private static function integer(string $key, int $default): int
    {
        return EnvLoader::getInt($key) ?? $default;
    }

    /**
     * @param string $key
     * @param VectorMetric $default
     * @return VectorMetric
     */
    private static function metric(string $key, VectorMetric $default): VectorMetric
    {
        $value = strtolower(trim(EnvLoader::get($key) ?? ''));
        if ($value === '') {
            return $default;
        }

        return VectorMetric::tryFrom($value)
            ?? throw new InvalidArgumentException("Environment variable {$key} contains an unsupported metric.");
    }

    /**
     * @param string $key
     * @param float|null $default
     * @return float|null
     */
    private static function nullableFloat(string $key, ?float $default): ?float
    {
        $value = EnvLoader::get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['null', 'none', 'off'], true)) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Environment variable {$key} must be a number or null.");
        }

        $float = (float)$value;
        if (!is_finite($float)) {
            throw new InvalidArgumentException("Environment variable {$key} must be finite.");
        }

        return $float;
    }
}
