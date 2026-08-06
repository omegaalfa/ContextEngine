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

    /**
     * Reads an environment already populated by the process or EnvLoader.
     *
     * @return ContextEngineConfig
     */
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
            heuristicQueryPlanning: self::boolean('CONTEXT_ENGINE_HEURISTIC_QUERY_PLANNING', false),
            neighborBefore: self::integer('CONTEXT_ENGINE_NEIGHBOR_BEFORE', 0),
            neighborAfter: self::integer('CONTEXT_ENGINE_NEIGHBOR_AFTER', 0),
            fusedLimit: self::nullableInteger('CONTEXT_ENGINE_FUSED_LIMIT'),
            contextChunkLimit: self::nullableInteger('CONTEXT_ENGINE_CONTEXT_CHUNK_LIMIT'),
            maximumContextCharacters: self::nullableInteger('CONTEXT_ENGINE_MAXIMUM_CONTEXT_CHARACTERS'),
            adaptiveContextSelection: self::boolean('CONTEXT_ENGINE_ADAPTIVE_CONTEXT_SELECTION', false),
            contextMaximumDistanceGap: self::float('CONTEXT_ENGINE_CONTEXT_MAXIMUM_DISTANCE_GAP', 0.08),
            contextMinimumSources: self::integer('CONTEXT_ENGINE_CONTEXT_MINIMUM_SOURCES', 1),
            contextMaximumSources: self::integer('CONTEXT_ENGINE_CONTEXT_MAXIMUM_SOURCES', 5),
            contextPreferSameDocument: self::boolean('CONTEXT_ENGINE_CONTEXT_PREFER_SAME_DOCUMENT', true),
            hybridSearch: self::boolean('CONTEXT_ENGINE_HYBRID_SEARCH', false),
            noEvidenceMessage: self::string(
                'CONTEXT_ENGINE_NO_EVIDENCE_MESSAGE',
                'Não encontrei evidências suficientes no contexto recuperado para responder a essa pergunta.',
            ),
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

    private static function nullableInteger(string $key): ?int
    {
        $value = EnvLoader::get($key);
        if ($value === null || trim($value) === '' || in_array(strtolower(trim($value)), ['null', 'none', 'off'], true)) {
            return null;
        }
        return EnvLoader::getInt($key)
            ?? throw new InvalidArgumentException('Environment variable ' . $key . ' must be an integer or null.');
    }

    private static function boolean(string $key, bool $default): bool
    {
        $value = EnvLoader::get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? throw new InvalidArgumentException('Environment variable ' . $key . ' must be boolean.');
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

    private static function float(string $key, float $default): float
    {
        $value = EnvLoader::get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Environment variable ' . $key . ' must be numeric.');
        }
        $float = (float)$value;
        if (!is_finite($float)) {
            throw new InvalidArgumentException('Environment variable ' . $key . ' must be finite.');
        }
        return $float;
    }
}
