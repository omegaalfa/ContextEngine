<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Http;

use JsonException;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Response;

final readonly class JsonClient
{
    public function __construct(private AsyncHttpClient $client) {}
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function post(string $url, array $payload): array
    {
        $future = $this->client->withJson()->post($url, $payload);
        $response = $future->await();
        if (!$response instanceof Response) {
            throw new ProviderException('HTTP client returned an unexpected response type.');
        }
        return $this->decode($response);
    }
    /** @return array<string,mixed> */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw new ProviderException("Provider returned HTTP {$response->status()}: {$response->text()}");
        }
        try {
            $data = $response->json();
        } catch (JsonException $e) {
            throw new ProviderException('Provider returned invalid JSON.', previous: $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ProviderException('Provider JSON root must be an object.');
        }
        $object = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new ProviderException('Provider JSON object has an invalid key.');
            } $object[$key] = $value;
        } return $object;
    }
}
