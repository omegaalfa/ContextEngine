<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Bootstrap\Config\DatabaseConfig;
use Omegaalfa\ContextEngine\Bootstrap\Config\OllamaConfig;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfig;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapConfigTest extends TestCase
{
    public function testConfigurationKeepsValidatedRetrievalAndIngestionOptions(): void
    {
        $config = new ContextEngineConfig(
            database: $this->database(),
            ollama: $this->ollama(),
            collection: 'financeiro',
            status: 'active',
            batchSize: 16,
            concurrency: 3,
            chunkSize: 800,
            overlap: 100,
            retrievalLimit: 8,
            retrievalMetric: VectorMetric::L2,
            maximumDistance: 0.7,
            adaptiveContextSelection: true,
            contextMaximumDistanceGap: 0.09,
            contextMinimumSources: 2,
            contextMaximumSources: 4,
            contextPreferSameDocument: false,
            hybridSearch: true,
        );

        self::assertSame('financeiro', $config->collection);
        self::assertSame(VectorMetric::L2, $config->retrievalMetric);
        self::assertSame(0.7, $config->maximumDistance);
        self::assertTrue($config->adaptiveContextSelection);
        self::assertSame(0.09, $config->contextMaximumDistanceGap);
        self::assertSame(2, $config->contextMinimumSources);
        self::assertSame(4, $config->contextMaximumSources);
        self::assertFalse($config->contextPreferSameDocument);
        self::assertTrue($config->hybridSearch);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testConfigurationRejectsInvalidStructuralValues(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContextEngineConfig(...array_replace([
            'database' => $this->database(),
            'ollama' => $this->ollama(),
        ], $values));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'empty collection' => [['collection' => '']];
        yield 'empty status' => [['status' => '']];
        yield 'zero batch' => [['batchSize' => 0]];
        yield 'zero concurrency' => [['concurrency' => 0]];
        yield 'overlap reaches chunk' => [['chunkSize' => 100, 'overlap' => 100]];
        yield 'retrieval over limit' => [['retrievalLimit' => 101]];
        yield 'negative distance' => [['maximumDistance' => -0.1]];
        yield 'negative context gap' => [['contextMaximumDistanceGap' => -0.1]];
        yield 'zero context minimum' => [['contextMinimumSources' => 0]];
        yield 'context maximum below minimum' => [[
            'contextMinimumSources' => 3,
            'contextMaximumSources' => 2,
        ]];
    }

    public function testDatabaseAndOllamaConfigurationRejectInvalidValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DatabaseConfig('', 'context_engine', 5432, 'user', 'password');
    }

    public function testOllamaConfigurationRejectsInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OllamaConfig('bge-m3', 1024, 'not-a-url');
    }

    private function database(): DatabaseConfig
    {
        return new DatabaseConfig('127.0.0.1', 'context_engine', 54339, 'context_engine', 'context_engine');
    }

    private function ollama(): OllamaConfig
    {
        return new OllamaConfig('bge-m3', 1024, 'http://127.0.0.1:11434');
    }
}
