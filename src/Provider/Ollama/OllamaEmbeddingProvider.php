<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Ollama;

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

final readonly class OllamaEmbeddingProvider implements EmbeddingProvider
{

    /**
     * @var JsonClient
     */
    private JsonClient $http;

    /**
     * @param string $model
     * @param int $dimensions
     * @param AsyncHttpClient $client
     * @param string $baseUrl
     */
    public function __construct(
        public string   $model,
        private int     $dimensions,
        AsyncHttpClient $client = new AsyncHttpClient(),
        private string  $baseUrl = 'http://127.0.0.1:11434'
    )
    {
        $this->http = new JsonClient($client);
    }

    /**
     * @return EmbeddingSpace
     */
    public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('ollama', $this->model, $this->dimensions);
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
            throw new ProviderException('Requested embedding space is incompatible with Ollama provider.');
        }
        $input = $request->texts;
        $response = $this->http->post($this->baseUrl . '/api/embed', ['model' => $this->model, 'input' => $input]);
        $items = $response['embeddings'] ?? null;
        if (!is_array($items) || count($items) !== count($input)) {
            throw new ProviderException('Ollama returned a different embedding batch size.');
        }
        $result = [];
        foreach ($items as $raw) {
            if (!is_array($raw)) {
                throw new ProviderException('Ollama embedding response is invalid.');
            }
            $values = [];
            foreach ($raw as $value) {
                $values[] = $value;
            }
            $result[] = new Embedding($values, $this->space());
        }
        return $result;
    }
}
