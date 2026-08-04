<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Closure;
use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineContext;
use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Ingestion\IngestionReport;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Rag\Answer;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagExecution;
use PHPUnit\Framework\TestCase;

final class HighLevelApiTest extends TestCase
{
    public function testCreateBuilderBuildsContextEngineContext(): void
    {
        $engine = ContextEngine::create()
            ->tenant('empresa')
            ->collection('algorithms')
            ->ollama(
                baseUrl: 'http://127.0.0.1:11434',
                embeddingModel: 'bge-m3',
                languageModel: 'llama3.1:8b',
            )
            ->ingestion(batchSize: 16, concurrency: 2, chunkSize: 500, chunkOverlap: 25)
            ->retrieval(retrievalLimit: 2, maximumDistance: 0.5)
            ->build();

        $this->assertInstanceOf(ContextEngineContext::class, $engine);
    }

    public function testFromEnvironmentBuildsAndAllowsFluentOverrides(): void
    {
        $engine = ContextEngine::fromEnvironment()
            ->tenant('override-tenant')
            ->collection('override-collection')
            ->status('active')
            ->build();

        $this->assertInstanceOf(ContextEngineContext::class, $engine);
    }

    public function testFluentOverridesTakePrecedenceOverEnvironmentConfig(): void
    {
        $engine = ContextEngine::fromEnvironment()
            ->tenant('tenant-a')
            ->collection('collection-a')
            ->status('active')
            ->build();

        $this->assertInstanceOf(ContextEngineContext::class, $engine);
    }

    public function testOpenAiAndCustomComponentsCanBeComposed(): void
    {
        $engine = ContextEngine::create()
            ->openAi(apiKey: 'test-key', model: 'text-embedding-3-small')
            ->withCustomComponents(static function (): array {
                return [];
            })
            ->build();

        $this->assertInstanceOf(ContextEngineContext::class, $engine);
    }

    public function testPublicHighLevelActionsDelegateToExistingPipelines(): void
    {
        $engine = ContextEngine::create()->build();
        $this->assertInstanceOf(IngestionReport::class, $engine->ingest(new class () implements DocumentLoader {
            public function load(): iterable
            {
                return [];
            }
        }));

        $question = new Question('teste', 'tenant');
        $this->assertIsArray($engine->search($question));
        $this->assertInstanceOf(Answer::class, $engine->ask($question));
        $this->assertIsArray($engine->searchWithDiagnostics($question));
        $this->assertInstanceOf(RagExecution::class, $engine->askWithDiagnostics($question));
    }
}
