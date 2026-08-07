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
        public string $languageModel = 'llama3.1:8b',
    ) {
        if (trim($model) === '' || trim($languageModel) === '' || $dimensions < 1) {
            throw new InvalidArgumentException('Ollama embedding model, language model, and positive dimensions are required.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Ollama base URL must be valid.');
        }
    }
}
