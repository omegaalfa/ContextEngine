<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OllamaLanguageModelTest extends TestCase
{
    public function testItIsBufferedAndHasStableGenerationIdentity(): void
    {
        $client = new AsyncHttpClient();
        $first = new OllamaLanguageModel('qwen3:8b', $client, options: ['top_p' => 0.9, 'temperature' => 0.2]);
        $second = new OllamaLanguageModel('qwen3:8b', $client, options: ['temperature' => 0.2, 'top_p' => 0.9]);

        self::assertFalse(is_a(OllamaLanguageModel::class, StreamingLanguageModel::class, true));
        self::assertSame($first->generationFingerprint(), $second->generationFingerprint());
        self::assertNotSame(
            $first->generationFingerprint(),
            new OllamaLanguageModel('qwen3:8b', $client, options: ['temperature' => 0.8])->generationFingerprint(),
        );
    }

    public function testItExtractsOnlyACompletedAssistantResponse(): void
    {
        $content = new ReflectionMethod(OllamaLanguageModel::class, 'content');

        self::assertSame('Resposta local.', $content->invoke(null, [
            'done' => true,
            'message' => ['role' => 'assistant', 'content' => 'Resposta local.'],
        ]));
    }

    #[DataProvider('invalidResponses')]
    public function testItRejectsInvalidOrPartialResponses(array $response): void
    {
        $this->expectException(ProviderException::class);
        new ReflectionMethod(OllamaLanguageModel::class, 'content')->invoke(null, $response);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidResponses(): iterable
    {
        yield 'not done' => [['done' => false, 'message' => ['content' => 'partial']]];
        yield 'missing message' => [['done' => true]];
        yield 'empty content' => [['done' => true, 'message' => ['content' => '  ']]];
    }

    #[DataProvider('invalidConfiguration')]
    public function testItRejectsInvalidConfiguration(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'empty model' => [static fn (): object => new OllamaLanguageModel('', new AsyncHttpClient())];
        yield 'invalid URL' => [static fn (): object => new OllamaLanguageModel('qwen3:8b', new AsyncHttpClient(), 'ftp://localhost')];
        yield 'invalid option' => [static fn (): object => new OllamaLanguageModel('qwen3:8b', new AsyncHttpClient(), options: ['temperature' => INF])];
        yield 'invalid keep alive' => [static fn (): object => new OllamaLanguageModel('qwen3:8b', new AsyncHttpClient(), keepAlive: -1)];
    }
}
