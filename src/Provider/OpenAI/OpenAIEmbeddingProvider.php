<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\OpenAI;

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

final readonly class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    private JsonClient $http;
    public function __construct(string $apiKey, public string $model = 'text-embedding-3-small', private int $dimensions = 1536, AsyncHttpClient $client = new AsyncHttpClient(), private string $baseUrl = 'https://api.openai.com/v1')
    {
        $this->http = new JsonClient($client->withBearerToken($apiKey));
    }
    public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('openai', $this->model, $this->dimensions, '1', ['dimensions' => $this->dimensions]);
    }
    public function embed(string $text, string $tenantId): Embedding
    {
        return $this->embedBatch(new EmbeddingBatchRequest($tenantId, [$text], $this->space()))[0];
    }
    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        if ($request->expectedSpace->fingerprint() !== $this->space()->fingerprint()) {
            throw new ProviderException('Requested embedding space is incompatible with OpenAI provider.');
        } $input = $request->texts;
        $response = $this->http->post($this->baseUrl . '/embeddings', ['model' => $this->model, 'input' => $input, 'dimensions' => $this->dimensions]);
        $data = $response['data'] ?? null;
        if (!is_array($data) || count($data) !== count($input)) {
            throw new ProviderException('OpenAI returned a different embedding batch size.');
        }
        $result = [];
        foreach ($data as $item) {
            $raw = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (!is_array($raw)) {
                throw new ProviderException('OpenAI embedding response is missing data.');
            } $values = [];
            foreach ($raw as $value) {
                $values[] = $value;
            } $result[] = new Embedding($values, $this->space());
        }
        return $result;
    }
}
