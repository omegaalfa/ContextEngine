<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineContext;
use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Exception\StreamingNotSupportedException;
use Omegaalfa\ContextEngine\Ingestion\IngestionReport;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
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
            ->retrieval(retrievalLimit: 2, maximumDistance: 0.5, hybridSearch: true)
            ->build();

        $this->assertInstanceOf(ContextEngineContext::class, $engine);
    }

    public function testCreateReadsEnvironmentAndAllowsFluentOverrides(): void
    {
        $engine = ContextEngine::create()
            ->tenant('override-tenant')
            ->collection('override-collection')
            ->status('active')
            ->build();

        $this->assertInstanceOf(ContextEngineContext::class, $engine);
    }

    public function testEnvironmentCompositionSeparatesEmbeddingAndLanguageModels(): void
    {
        $context = ContextEngine::create()->build();
        $property = new \ReflectionProperty($context->rag, 'model');
        $model = $property->getValue($context->rag);

        self::assertInstanceOf(OllamaLanguageModel::class, $model);
        self::assertSame('llama3.1:8b', $model->model);
        self::assertNotSame($context->embeddings->space()->model, $model->model);
    }

    public function testFluentOverridesTakePrecedenceOverEnvironmentConfig(): void
    {
        $engine = ContextEngine::create()
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

    public function testOllamaFluentCompositionUsesConfiguredLanguageModel(): void
    {
        $context = ContextEngine::create()
            ->ollama(
                baseUrl: 'http://127.0.0.1:11434',
                embeddingModel: 'bge-m3',
                languageModel: 'llama3.1:8b',
            )
            ->build();

        $property = new \ReflectionProperty($context->rag, 'model');
        /** @var object $model */
        $model = $property->getValue($context->rag);

        self::assertInstanceOf(OllamaLanguageModel::class, $model);
        self::assertSame('llama3.1:8b', $model->model);
    }

    public function testOpenAiFluentCompositionUsesOpenAiLanguageModelByDefault(): void
    {
        $context = ContextEngine::create()
            ->openAi(apiKey: 'test-key', model: 'text-embedding-3-small')
            ->build();

        $property = new \ReflectionProperty($context->rag, 'model');
        /** @var object $model */
        $model = $property->getValue($context->rag);

        self::assertInstanceOf(OpenAILanguageModel::class, $model);
        self::assertSame('gpt-4.1-mini', $model->model);
    }

    public function testOpenAiLanguageModelFluentOverrideIsApplied(): void
    {
        $context = ContextEngine::create()
            ->openAi(apiKey: 'test-key', model: 'text-embedding-3-small')
            ->openAiLanguageModel(apiKey: 'test-key', model: 'gpt-4.1')
            ->build();

        $property = new \ReflectionProperty($context->rag, 'model');
        /** @var object $model */
        $model = $property->getValue($context->rag);

        self::assertInstanceOf(OpenAILanguageModel::class, $model);
        self::assertSame('gpt-4.1', $model->model);
    }

    public function testHybridRetrievalAppliesConfiguredRankingWeights(): void
    {
        $context = ContextEngine::create()
            ->retrieval(hybridSearch: true, vectorWeight: 0.4, lexicalWeight: 1.2)
            ->build();
        $property = new \ReflectionProperty($context->retriever, 'rankingWeights');

        self::assertSame(['vector' => 0.4, 'lexical' => 1.2], $property->getValue($context->retriever));
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

        $this->expectException(StreamingNotSupportedException::class);
        iterator_to_array($engine->stream($question));
    }

    public function testRootFacadeExposesStreamDelegation(): void
    {
        $engine = ContextEngine::create();

        $this->expectException(StreamingNotSupportedException::class);
        iterator_to_array($engine->stream(new Question('teste', 'tenant')));
    }
}
