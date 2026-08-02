<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Provider\Support\EmbeddingResponseValidator;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProviderValidationTest extends TestCase
{
    public function testOpenAiRestoresInputOrderFromResponseIndexes(): void
    {
        $space = new EmbeddingSpace('openai', 'model', 2);

        $embeddings = EmbeddingResponseValidator::orderedOpenAI([
            ['index' => 1, 'embedding' => [3, 4]],
            ['index' => 0, 'embedding' => [1, 2]],
        ], 2, $space);

        self::assertSame([[1.0, 2.0], [3.0, 4.0]], array_map(static fn ($embedding): array => $embedding->values, $embeddings));
    }

    #[DataProvider('invalidOpenAiResponses')]
    public function testOpenAiRejectsInvalidOrPartialResponses(mixed $response): void
    {
        $this->expectException(ProviderException::class);
        EmbeddingResponseValidator::orderedOpenAI($response, 2, new EmbeddingSpace('openai', 'model', 2));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOpenAiResponses(): iterable
    {
        yield 'wrong cardinality' => [[['index' => 0, 'embedding' => [1, 2]]]];
        yield 'duplicate index' => [[['index' => 0, 'embedding' => [1, 2]], ['index' => 0, 'embedding' => [3, 4]]]];
        yield 'wrong dimensions' => [[['index' => 0, 'embedding' => [1]], ['index' => 1, 'embedding' => [3, 4]]]];
        yield 'non numeric' => [[['index' => 0, 'embedding' => [1, 'secret']], ['index' => 1, 'embedding' => [3, 4]]]];
        yield 'infinite' => [[['index' => 0, 'embedding' => [1, INF]], ['index' => 1, 'embedding' => [3, 4]]]];
    }

    public function testOllamaValidatesCardinalityDimensionsAndPosition(): void
    {
        $space = new EmbeddingSpace('ollama', 'bge-m3', 2);
        $embeddings = EmbeddingResponseValidator::positional([[1, 2], [3, 4]], 2, $space, 'Ollama');
        self::assertSame([[1.0, 2.0], [3.0, 4.0]], array_map(static fn ($embedding): array => $embedding->values, $embeddings));

        $this->expectException(ProviderException::class);
        EmbeddingResponseValidator::positional([[1, 2]], 2, $space, 'Ollama');
    }

    #[DataProvider('invalidConfigurations')]
    public function testEmbeddingProvidersRejectInvalidConfiguration(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty api key' => [static fn (): object => new OpenAIEmbeddingProvider('')];
        yield 'empty OpenAI model' => [static fn (): object => new OpenAIEmbeddingProvider('key', '  ')];
        yield 'invalid OpenAI dimensions' => [static fn (): object => new OpenAIEmbeddingProvider('key', dimensions: 0)];
        yield 'invalid OpenAI URL' => [static fn (): object => new OpenAIEmbeddingProvider('key', baseUrl: 'not-a-url')];
        yield 'credential in URL' => [static fn (): object => new OpenAIEmbeddingProvider('key', baseUrl: 'https://user:pass@example.test')];
        yield 'empty Ollama model' => [static fn (): object => new OllamaEmbeddingProvider('', 2)];
        yield 'invalid Ollama dimensions' => [static fn (): object => new OllamaEmbeddingProvider('model', -1)];
        yield 'invalid Ollama URL' => [static fn (): object => new OllamaEmbeddingProvider('model', 2, baseUrl: 'ftp://example.test')];
        yield 'empty language model' => [static fn (): object => new OpenAILanguageModel('key', ' ')];
    }

    public function testHttpFailureDoesNotExposeProviderResponseBody(): void
    {
        $client = new JsonClient(new AsyncHttpClient());
        $response = new Response(401, new Headers(), '{"error":"secret provider detail"}');
        $decode = new ReflectionMethod($client, 'decode');

        try {
            $decode->invoke($client, $response);
            self::fail('Expected provider exception.');
        } catch (ProviderException $exception) {
            self::assertSame('Provider returned HTTP 401.', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }
}
