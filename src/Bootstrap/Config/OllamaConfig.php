<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap\Config;

use InvalidArgumentException;

final readonly class OllamaConfig
{
    public function __construct(
        public string $model,
        public int $dimensions,
        public string $baseUrl,
    ) {
        if (trim($model) === '' || $dimensions < 1) {
            throw new InvalidArgumentException('Ollama model and positive dimensions are required.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Ollama base URL must be valid.');
        }
    }
}
