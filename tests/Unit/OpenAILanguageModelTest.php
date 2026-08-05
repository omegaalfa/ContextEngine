<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OpenAILanguageModelTest extends TestCase
{
    public function testItClaimsStreamingCapability(): void
    {
        self::assertTrue(is_a(OpenAILanguageModel::class, StreamingLanguageModel::class, true));
    }

    public function testItExtractsTextDeltaFromSsePayload(): void
    {
        $method = new ReflectionMethod(OpenAILanguageModel::class, 'contentFromPayload');
        $method->setAccessible(true);

        self::assertSame('Ola', $method->invoke(null, [
            'choices' => [[
                'delta' => ['content' => 'Ola'],
            ]],
        ]));

        self::assertNull($method->invoke(null, [
            'choices' => [[
                'delta' => ['tool_calls' => []],
            ]],
        ]));
    }

    public function testItExtractsProviderErrorFromSsePayload(): void
    {
        $method = new ReflectionMethod(OpenAILanguageModel::class, 'errorMessageFromPayload');
        $method->setAccessible(true);

        self::assertSame(
            'OpenAI streaming request failed: Rate limit exceeded.',
            $method->invoke(null, ['error' => ['message' => 'Rate limit exceeded.']]),
        );
        self::assertSame(
            'OpenAI streaming request failed.',
            $method->invoke(null, ['error' => ['type' => 'invalid_request_error']]),
        );
        self::assertNull($method->invoke(null, ['choices' => []]));
    }

    public function testItRejectsNonObjectSsePayload(): void
    {
        $method = new ReflectionMethod(OpenAILanguageModel::class, 'decodeSsePayload');
        $method->setAccessible(true);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI streaming event is not a JSON object.');
        $method->invoke(null, '["not-an-object"]');
    }

    public function testConstructorStillValidatesConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenAILanguageModel('', 'gpt-4.1-mini', new AsyncHttpClient());
    }
}
