<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Integration;

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineContext;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;
use PHPUnit\Framework\TestCase;
use Throwable;

final class BootstrapIntegrationTest extends TestCase
{
    public function testBootstrapBuildsTypedContextAndSuppliesSharedHttpClient(): void
    {
        if (getenv('CONTEXT_ENGINE_RUN_PGVECTOR_TESTS') !== '1') {
            self::markTestSkipped('Set CONTEXT_ENGINE_RUN_PGVECTOR_TESTS=1 to run pgvector integration tests.');
        }

        EnvLoader::load(dirname(__DIR__, 2) . '/.env');
        $receivedClient = null;

        try {
            $context = Bootstrap::create(
                ContextEngineConfigFactory::fromEnvironment(),
                static function (AsyncHttpClient $http) use (&$receivedClient): LanguageModel {
                    $receivedClient = $http;

                    return new class () implements LanguageModel {
                        public function complete(array $messages): string
                        {
                            return 'test';
                        }
                    };
                },
            );
        } catch (Throwable $error) {
            self::fail('Bootstrap integration is enabled but its environment is unavailable: ' . $error->getMessage());
        }

        self::assertInstanceOf(ContextEngineContext::class, $context);
        self::assertInstanceOf(AsyncHttpClient::class, $receivedClient);
        self::assertInstanceOf(OllamaEmbeddingProvider::class, $context->embeddings);
        self::assertInstanceOf(PgVectorStore::class, $context->store);
    }
}
