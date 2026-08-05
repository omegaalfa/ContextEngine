<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ArchitectureTest extends TestCase
{
    public function testOpenAiProviderClaimsStreamingCapability(): void
    {
            self::assertTrue(is_a(OpenAILanguageModel::class, StreamingLanguageModel::class, true));
    }
    public function testFutureAppearsOnlyInInfrastructureSource(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }$relative = str_replace($root.'/', '', $file->getPathname());
            if (str_starts_with($relative, 'Infrastructure/')) {
                continue;
            }self::assertStringNotContainsString('Omegaalfa\\FiberEventLoop\\Future', (string)file_get_contents($file->getPathname()), $relative);
        }
    }

    public function testContractsDoNotDependOnInfrastructureNamespaces(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Contract';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('Omegaalfa\\QueryBuilder', $contents, $file->getFilename());
            self::assertStringNotContainsString('Omegaalfa\\ContextEngine\\VectorStore\\PgVectorStore', $contents, $file->getFilename());
            self::assertStringNotContainsString('PDO', $contents, $file->getFilename());
        }
    }

    public function testFacadeContextEngineHasSingleDeclaredClass(): void
    {
        $highLevelClass = 'Omegaalfa\\ContextEngine\\HighLevel\\ContextEngine';
        self::assertTrue(class_exists($highLevelClass));

        $root = new ReflectionClass(\Omegaalfa\ContextEngine\ContextEngine::class);
        $highLevel = new ReflectionClass($highLevelClass);

        self::assertSame(
            $root->getName(),
            $highLevel->getName(),
            'HighLevel\\ContextEngine must remain an alias to the canonical root facade.',
        );
    }
}
