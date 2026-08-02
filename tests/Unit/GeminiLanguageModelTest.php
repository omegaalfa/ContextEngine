<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Prompt\Role;
use Omegaalfa\ContextEngine\Provider\Gemini\GeminiLanguageModel;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

final class GeminiLanguageModelTest extends TestCase
{
    public function testItIsBufferedAndHasStableGenerationIdentity(): void
    {
        $client = new AsyncHttpClient();
        $first = new GeminiLanguageModel('key', 'gemini-2.5-flash', $client, generationConfig: [
            'topP' => 0.9,
            'temperature' => 0.2,
        ]);
        $second = new GeminiLanguageModel('key', 'gemini-2.5-flash', $client, generationConfig: [
            'temperature' => 0.2,
            'topP' => 0.9,
        ]);

        self::assertFalse(is_a(GeminiLanguageModel::class, StreamingLanguageModel::class, true));
        self::assertSame($first->generationFingerprint(), $second->generationFingerprint());
        self::assertNotSame(
            $first->generationFingerprint(),
            new GeminiLanguageModel('key', 'gemini-2.5-flash', $client, generationConfig: ['temperature' => 0.8])
                ->generationFingerprint(),
        );
    }

    public function testItMapsSystemUserAndAssistantMessagesToGeminiPayload(): void
    {
        $model = new GeminiLanguageModel('key', 'gemini-2.5-flash', new AsyncHttpClient(), generationConfig: [
            'temperature' => 0.2,
        ]);
        $payload = new ReflectionMethod(GeminiLanguageModel::class, 'payload')->invoke($model, [
            new ChatMessage(Role::SYSTEM, 'Use apenas o contexto.'),
            new ChatMessage(Role::USER, 'Qual é o prazo?'),
            new ChatMessage(Role::ASSISTANT, 'Trinta dias.'),
        ]);

        self::assertSame([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Qual é o prazo?']]],
                ['role' => 'model', 'parts' => [['text' => 'Trinta dias.']]],
            ],
            'systemInstruction' => ['parts' => [['text' => 'Use apenas o contexto.']]],
            'generationConfig' => ['temperature' => 0.2],
        ], $payload);
    }

    public function testItConcatenatesVisibleTextPartsAndIgnoresThoughts(): void
    {
        $content = new ReflectionMethod(GeminiLanguageModel::class, 'content');

        self::assertSame('Resposta final.', $content->invoke(null, [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [
                    ['thought' => true, 'text' => 'Raciocínio interno'],
                    ['text' => 'Resposta '],
                    ['text' => 'final.'],
                ]],
            ]],
        ]));
    }

    #[DataProvider('invalidResponses')]
    public function testItRejectsResponsesWithoutUsableText(array $response, string $message): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);
        new ReflectionMethod(GeminiLanguageModel::class, 'content')->invoke(null, $response);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidResponses(): iterable
    {
        yield 'blocked prompt' => [['promptFeedback' => ['blockReason' => 'SAFETY']], 'Gemini blocked the prompt (SAFETY).'];
        yield 'candidate stopped without text' => [['candidates' => [['finishReason' => 'SAFETY']]], 'Gemini returned no usable text (SAFETY).'];
        yield 'unsafe partial text' => [[
            'candidates' => [[
                'finishReason' => 'SAFETY',
                'content' => ['parts' => [['text' => 'partial']]],
            ]],
        ], 'Gemini returned no usable text (SAFETY).'];
        yield 'missing candidate' => [[], 'Gemini response is missing usable text content.'];
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
        yield 'empty API key' => [static fn (): object => new GeminiLanguageModel('', 'gemini-2.5-flash', new AsyncHttpClient())];
        yield 'empty model' => [static fn (): object => new GeminiLanguageModel('key', '', new AsyncHttpClient())];
        yield 'invalid URL' => [static fn (): object => new GeminiLanguageModel('key', 'gemini-2.5-flash', new AsyncHttpClient(), 'ftp://example.com')];
        yield 'non-finite value' => [static fn (): object => new GeminiLanguageModel('key', 'gemini-2.5-flash', new AsyncHttpClient(), generationConfig: ['temperature' => INF])];
        yield 'object value' => [static fn (): object => new GeminiLanguageModel('key', 'gemini-2.5-flash', new AsyncHttpClient(), generationConfig: ['invalid' => new stdClass()])];
    }

    public function testItRequiresANonSystemMessage(): void
    {
        $model = new GeminiLanguageModel('key', 'gemini-2.5-flash', new AsyncHttpClient());

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('at least one non-system message');
        new ReflectionMethod(GeminiLanguageModel::class, 'payload')->invoke($model, [
            new ChatMessage(Role::SYSTEM, 'Instrução.'),
        ]);
    }
}
