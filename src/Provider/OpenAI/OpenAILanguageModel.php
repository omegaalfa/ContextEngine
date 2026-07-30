<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\OpenAI;

use Omegaalfa\ContextEngine\Contract\CacheableLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

final readonly class OpenAILanguageModel implements CacheableLanguageModel
{
    /**
     * @var JsonClient
     */
    private JsonClient $http;

    /**
     * @param string $apiKey
     * @param string $model
     * @param AsyncHttpClient $client
     * @param string $baseUrl
     */
    public function __construct(string $apiKey, public string $model = 'gpt-4.1-mini', AsyncHttpClient $client = new AsyncHttpClient(), private string $baseUrl = 'https://api.openai.com/v1')
    {
        $this->http = new JsonClient($client->withBearerToken($apiKey));
    }

    /**
     * @param array $messages
     * @return string
     */
    public function complete(array $messages): string
    {
        $response = $this->http->post($this->baseUrl . '/chat/completions', ['model' => $this->model, 'messages' => $this->messages($messages)]);
        $choices = $response['choices'] ?? null;
        $first = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (!is_string($content)) {
            throw new ProviderException('OpenAI completion response is missing content.');
        }
        return $content;
    }

    /**
     * @return string
     */
    public function generationFingerprint(): string
    {
        return hash('sha256', 'openai' . "\0" . $this->model . "\0" . 'default-parameters');
    }

    /**
     * @param list<ChatMessage> $messages
     * @return list<array{role:string,content:string}>
     */
    private function messages(array $messages): array
    {
        return array_map(static fn(ChatMessage $m): array => ['role' => $m->role->value, 'content' => $m->content], $messages);
    }
}
