<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Gemini;

use InvalidArgumentException;
use JsonException;
use Omegaalfa\ContextEngine\Contract\CacheableLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Prompt\Role;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\ContextEngine\Provider\Support\ProviderConfiguration;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

/** Buffered Gemini generateContent adapter. It intentionally does not claim streaming support. */
final readonly class GeminiLanguageModel implements CacheableLanguageModel
{
    /**
     * @var JsonClient
     */
    private JsonClient $http;

    /**
     * @var string
     */
    public string $model;

    /**
     * @var string
     */
    private string $baseUrl;

    /** @var array<string, mixed> */
    private array $generationConfig;

    /** @param array<string, mixed> $generationConfig Parameters accepted by Gemini generationConfig. */
    public function __construct(
        string          $apiKey,
        string          $model,
        AsyncHttpClient $client,
        string          $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        array           $generationConfig = [],
    )
    {
        $apiKey = ProviderConfiguration::nonEmpty($apiKey, 'Gemini API key');
        $this->model = ProviderConfiguration::nonEmpty($model, 'Gemini language model');
        $this->baseUrl = ProviderConfiguration::baseUrl($baseUrl);
        $this->generationConfig = self::canonicalObject($generationConfig, 'Gemini generation configuration');
        $this->http = new JsonClient($client->withHeader('x-goog-api-key', $apiKey));
    }

    /** @param list<ChatMessage> $messages */
    public function complete(array $messages): string
    {
        if ($messages === []) {
            throw new ProviderException('Gemini content generation requires at least one message.');
        }

        return self::content($this->http->post(
            sprintf('%s/models/%s:generateContent', $this->baseUrl, rawurlencode($this->model)),
            $this->payload($messages),
        ));
    }

    /**
     * @return string
     */
    public function generationFingerprint(): string
    {
        try {
            $config = json_encode($this->generationConfig, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('Gemini generation configuration cannot be fingerprinted.', previous: $exception);
        }

        return hash('sha256', implode("\0", ['gemini', $this->model, $config]));
    }

    /**
     * @param list<ChatMessage> $messages
     * @return array<string, mixed>
     */
    private function payload(array $messages): array
    {
        $systemParts = [];
        $contents = [];

        foreach ($messages as $message) {
            if ($message->role === Role::SYSTEM) {
                $systemParts[] = ['text' => $message->content];
                continue;
            }

            $contents[] = [
                'role' => $message->role === Role::ASSISTANT ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        if ($contents === []) {
            throw new ProviderException('Gemini content generation requires at least one non-system message.');
        }

        $payload = ['contents' => $contents];
        if ($systemParts !== []) {
            $payload['systemInstruction'] = ['parts' => $systemParts];
        }
        if ($this->generationConfig !== []) {
            $payload['generationConfig'] = $this->generationConfig;
        }

        return $payload;
    }

    /** @param array<string, mixed> $response */
    private static function content(array $response): string
    {
        $promptFeedback = $response['promptFeedback'] ?? null;
        $blockReason = is_array($promptFeedback) ? ($promptFeedback['blockReason'] ?? null) : null;
        if (is_string($blockReason) && $blockReason !== '') {
            throw new ProviderException("Gemini blocked the prompt ({$blockReason}).");
        }

        $candidates = $response['candidates'] ?? null;
        $candidate = is_array($candidates) ? ($candidates[0] ?? null) : null;
        $finishReason = is_array($candidate) ? ($candidate['finishReason'] ?? null) : null;
        $content = is_array($candidate) ? ($candidate['content'] ?? null) : null;
        $parts = is_array($content) ? ($content['parts'] ?? null) : null;
        $text = [];

        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (!is_array($part) || ($part['thought'] ?? false) === true) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $text[] = $part['text'];
                }
            }
        }

        $result = implode('', $text);
        if (trim($result) !== '' && in_array($finishReason, ['STOP', 'MAX_TOKENS'], true)) {
            return $result;
        }

        if (is_string($finishReason) && $finishReason !== '') {
            throw new ProviderException("Gemini returned no usable text ({$finishReason}).");
        }

        throw new ProviderException('Gemini response is missing usable text content.');
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function canonicalObject(array $value, string $path): array
    {
        foreach ($value as $key => $item) {
            if (trim($key) === '') {
                throw new InvalidArgumentException("{$path} keys must be non-empty strings.");
            }
            $value[$key] = self::canonicalValue($item, "{$path}.{$key}");
        }
        ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * @param mixed $value
     * @param string $path
     * @return mixed
     */
    private static function canonicalValue(mixed $value, string $path): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("{$path} must be finite.");
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException("{$path} must contain only JSON-compatible values.");
        }
        if (!array_is_list($value)) {
            $object = [];
            foreach ($value as $key => $item) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException("{$path} keys must be non-empty strings.");
                }
                $object[$key] = $item;
            }

            return self::canonicalObject($object, $path);
        }

        foreach ($value as $index => $item) {
            $value[$index] = self::canonicalValue($item, "{$path}[{$index}]");
        }

        return $value;
    }
}
