<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\HighLevel;

final readonly class ProviderConfig
{
    /**
     * @param string $provider
     * @param string|null $baseUrl
     * @param string|null $embeddingModel
     * @param string|null $languageModel
     * @param int|null $embeddingDimensions
     * @param string|null $apiKey
     * @param string|null $model
     */
    public function __construct(
        public string $provider,
        public ?string $baseUrl = null,
        public ?string $embeddingModel = null,
        public ?string $languageModel = null,
        public ?int $embeddingDimensions = null,
        public ?string $apiKey = null,
        public ?string $model = null,
    ) {
    }
}
