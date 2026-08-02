<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Ollama;

use InvalidArgumentException;
use JsonException;
use Omegaalfa\ContextEngine\Contract\CacheableLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\ContextEngine\Provider\Support\ProviderConfiguration;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

/** Buffered Ollama chat adapter. It intentionally does not claim streaming support. */
final readonly class OllamaLanguageModel implements CacheableLanguageModel
{
    private JsonClient $http;

    public string $model;

    private string $baseUrl;

    private string|int|null $keepAlive;

    /** @var array<string, bool|float|int|string> */
    private array $options;

    /**
     * @param array<string, bool|float|int|string> $options Generation options understood by Ollama.
     */
    public function __construct(
        string $model,
        AsyncHttpClient $client,
        string $baseUrl = 'http://127.0.0.1:11434',
        array $options = [],
        string|int|null $keepAlive = null,
    ) {
        $this->model = ProviderConfiguration::nonEmpty($model, 'Ollama language model');
        $this->baseUrl = ProviderConfiguration::baseUrl($baseUrl);
        $this->options = $this->validatedOptions($options);
        if (is_string($keepAlive) && trim($keepAlive) === '' || is_int($keepAlive) && $keepAlive < 0) {
            throw new InvalidArgumentException('Ollama keep-alive must be a non-empty duration or a non-negative integer.');
        }
        $this->keepAlive = $keepAlive;
        $this->http = new JsonClient($client);
    }

    /** @param list<ChatMessage> $messages */
    public function complete(array $messages): string
    {
        if ($messages === []) {
            throw new ProviderException('Ollama chat requires at least one message.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => array_map(
                static fn (ChatMessage $message): array => [
                    'role' => $message->role->value,
                    'content' => $message->content,
                ],
                $messages,
            ),
            'stream' => false,
        ];
        if ($this->options !== []) {
            $payload['options'] = $this->options;
        }
        if ($this->keepAlive !== null) {
            $payload['keep_alive'] = $this->keepAlive;
        }

        return self::content($this->http->post($this->baseUrl . '/api/chat', $payload));
    }

    public function generationFingerprint(): string
    {
        try {
            $options = json_encode($this->options, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('Ollama generation options cannot be fingerprinted.', previous: $exception);
        }

        return hash('sha256', implode("\0", ['ollama', $this->model, $options]));
    }

    /** @param array<string, mixed> $response */
    private static function content(array $response): string
    {
        $message = $response['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (($response['done'] ?? null) !== true || !is_string($content) || trim($content) === '') {
            throw new ProviderException('Ollama chat response is incomplete or missing content.');
        }

        return $content;
    }

    /**
     * @param array<string, bool|float|int|string> $options
     * @return array<string, bool|float|int|string>
     */
    private function validatedOptions(array $options): array
    {
        foreach ($options as $name => $value) {
            if (trim($name) === '') {
                throw new InvalidArgumentException('Ollama generation option names cannot be empty.');
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException("Ollama generation option {$name} must be finite.");
            }
        }
        ksort($options, SORT_STRING);

        return $options;
    }
}
