<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Bootstrap\Config\DatabaseConfig;
use Omegaalfa\ContextEngine\Bootstrap\Config\OllamaConfig;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

final readonly class ContextEngineConfig
{
    /**
     * @param DatabaseConfig $database
     * @param OllamaConfig $ollama
     * @param string $collection
     * @param string $status
     * @param int $batchSize
     * @param int $concurrency
     * @param int $chunkSize
     * @param int $overlap
     * @param int $retrievalLimit
     * @param VectorMetric $retrievalMetric
     * @param float|null $maximumDistance
     */
    public function __construct(
        public DatabaseConfig $database,
        public OllamaConfig   $ollama,
        public string         $collection = 'default',
        public string         $status = 'active',
        public int            $batchSize = 32,
        public int            $concurrency = 4,
        public int            $chunkSize = 1_000,
        public int            $overlap = 150,
        public int            $retrievalLimit = 5,
        public VectorMetric   $retrievalMetric = VectorMetric::COSINE,
        public ?float         $maximumDistance = 0.45,
    ) {
        if (trim($collection) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Collection and status cannot be empty.');
        }
        if ($batchSize < 1 || $concurrency < 1) {
            throw new InvalidArgumentException('Batch size and concurrency must be positive.');
        }
        if ($chunkSize < 1 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new InvalidArgumentException('Overlap must be non-negative and smaller than chunk size.');
        }
        if ($retrievalLimit < 1 || $retrievalLimit > 100) {
            throw new InvalidArgumentException('Retrieval limit must be between 1 and 100.');
        }
        if ($maximumDistance !== null && (!is_finite($maximumDistance) || $maximumDistance < 0)) {
            throw new InvalidArgumentException('Maximum distance must be finite and non-negative.');
        }
    }
}
