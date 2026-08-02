<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\OpenAI;

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\ContextEngine\Provider\Support\EmbeddingResponseValidator;
use Omegaalfa\ContextEngine\Provider\Support\ProviderConfiguration;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

final readonly class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    private JsonClient $http;
    public string $model;
    private int $dimensions;
    private string $baseUrl;

    /**
     * @param string $apiKey
     * @param string $model
     * @param int $dimensions
     * @param AsyncHttpClient $client
     * @param string $baseUrl
     */
    public function __construct(string $apiKey, string $model = 'text-embedding-3-small', int $dimensions = 1536, AsyncHttpClient $client = new AsyncHttpClient(), string $baseUrl = 'https://api.openai.com/v1')
    {
        $apiKey = ProviderConfiguration::nonEmpty($apiKey, 'OpenAI API key');
        $this->model = ProviderConfiguration::nonEmpty($model, 'OpenAI embedding model');
        $this->dimensions = ProviderConfiguration::positiveDimensions($dimensions);
        $this->baseUrl = ProviderConfiguration::baseUrl($baseUrl);
        $this->http = new JsonClient($client->withBearerToken($apiKey));
    }

    /**
     * @return EmbeddingSpace
     */
    public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('openai', $this->model, $this->dimensions, '1', ['dimensions' => $this->dimensions]);
    }

    /**
     * @param string $text
     * @param string $tenantId
     * @return Embedding
     */
    public function embed(string $text, string $tenantId): Embedding
    {
        return $this->embedBatch(new EmbeddingBatchRequest($tenantId, [$text], $this->space()))[0];
    }

    /**
     * @param EmbeddingBatchRequest $request
     * @return array|Embedding[]
     */
    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        if ($request->expectedSpace->fingerprint() !== $this->space()->fingerprint()) {
            throw new ProviderException('Requested embedding space is incompatible with OpenAI provider.');
        }
        $input = $request->texts;
        $response = $this->http->post($this->baseUrl . '/embeddings', ['model' => $this->model, 'input' => $input, 'dimensions' => $this->dimensions]);
        return EmbeddingResponseValidator::orderedOpenAI($response['data'] ?? null, count($input), $this->space());
    }
}
