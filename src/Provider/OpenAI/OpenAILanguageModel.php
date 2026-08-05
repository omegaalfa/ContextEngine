<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\OpenAI;

use Omegaalfa\ContextEngine\Contract\CacheableLanguageModel;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\ContextEngine\Provider\Support\ProviderConfiguration;
use Omegaalfa\ContextEngine\Rag\AnswerDelta;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\SseEvent;
use Omegaalfa\HttpClient\Http\SseStream;
use RuntimeException;

final readonly class OpenAILanguageModel implements CacheableLanguageModel, StreamingLanguageModel
{
    /**
     * @var JsonClient
     */
    private JsonClient $http;
    private AsyncHttpClient $streamingHttp;
    public string $model;
    private string $baseUrl;

    /**
     * @param string $apiKey
     * @param string $model
     * @param AsyncHttpClient $client
     * @param string $baseUrl
     */
    public function __construct(string $apiKey, string $model = 'gpt-4.1-mini', AsyncHttpClient $client = new AsyncHttpClient(), string $baseUrl = 'https://api.openai.com/v1')
    {
        $apiKey = ProviderConfiguration::nonEmpty($apiKey, 'OpenAI API key');
        $this->model = ProviderConfiguration::nonEmpty($model, 'OpenAI language model');
        $this->baseUrl = ProviderConfiguration::baseUrl($baseUrl);
        $this->streamingHttp = $client->withBearerToken($apiKey);
        $this->http = new JsonClient($this->streamingHttp);
    }

    /**
     * @param array $messages
     * @return string
     */
    /** @param list<ChatMessage> $messages */
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

    /** @param list<ChatMessage> $messages */
    public function stream(array $messages): iterable
    {
        $future = $this->streamingHttp->streamSsePost(
            $this->baseUrl . '/chat/completions',
            body: [
                'model' => $this->model,
                'stream' => true,
                'messages' => $this->messages($messages),
            ],
            headers: ['Accept' => 'text/event-stream'],
            requireDone: true,
            completionDetector: AsyncHttpClient::doneMarkerCompletionDetector(),
        );
        $stream = $future->await();
        if (!$stream instanceof SseStream) {
            throw new ProviderException('OpenAI streaming request returned an unexpected stream type.');
        }
        $sequence = 0;

        try {
            /** @var SseEvent $event */
            foreach ($stream as $event) {
                if ($event->done()) {
                    yield new AnswerDelta('', $sequence, true);
                    return;
                }

                $payload = self::decodeSsePayload($event->data());
                $errorMessage = self::errorMessageFromPayload($payload);
                if ($errorMessage !== null) {
                    throw new ProviderException($errorMessage);
                }

                $content = self::contentFromPayload($payload);
                if ($content === null || $content === '') {
                    continue;
                }

                yield new AnswerDelta($content, $sequence, false);
                ++$sequence;
            }
        } catch (RuntimeException $exception) {
            throw new ProviderException('OpenAI streaming response is invalid or incomplete.', previous: $exception);
        }

        throw new ProviderException('OpenAI streaming response ended without a completion marker.');
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
        return array_map(static fn (ChatMessage $m): array => ['role' => $m->role->value, 'content' => $m->content], $messages);
    }

    /** @return array<string, mixed> */
    private static function decodeSsePayload(string $data): array
    {
        $payload = json_decode($data, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ProviderException('OpenAI streaming event is not a JSON object.');
        }

        $object = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                throw new ProviderException('OpenAI streaming event has an invalid key.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /** @param array<string, mixed> $payload */
    private static function errorMessageFromPayload(array $payload): ?string
    {
        $error = $payload['error'] ?? null;
        if (!is_array($error)) {
            return null;
        }

        $message = $error['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return 'OpenAI streaming request failed: ' . trim($message);
        }

        return 'OpenAI streaming request failed.';
    }

    /** @param array<string, mixed> $payload */
    private static function contentFromPayload(array $payload): ?string
    {
        $choices = $payload['choices'] ?? null;
        $firstChoice = is_array($choices) ? ($choices[0] ?? null) : null;
        $delta = is_array($firstChoice) ? ($firstChoice['delta'] ?? null) : null;
        $content = is_array($delta) ? ($delta['content'] ?? null) : null;

        return is_string($content) ? $content : null;
    }
}
