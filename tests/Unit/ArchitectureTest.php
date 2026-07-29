<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function testBufferedHttpProviderDoesNotClaimStreaming(): void
    {
        self::assertFalse(is_a(OpenAILanguageModel::class, StreamingLanguageModel::class, true));
    }
    public function testFutureAppearsOnlyInInfrastructureSource(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }$relative = str_replace($root.'/', '', $file->getPathname());
            if (str_starts_with($relative, 'Infrastructure/')) {
                continue;
            }self::assertStringNotContainsString('Omegaalfa\\FiberEventLoop\\Future', (string)file_get_contents($file->getPathname()), $relative);
        }
    }
}
