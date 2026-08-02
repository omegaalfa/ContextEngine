<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Redis;
use Throwable;

final class RedisCacheIntegrationTest extends TestCase
{
    public function testAuthenticatedPersistentRedisIsAvailable(): void
    {
        if (getenv('CONTEXT_ENGINE_RUN_REDIS_TESTS') !== '1') {
            self::markTestSkipped('Set CONTEXT_ENGINE_RUN_REDIS_TESTS=1 to enable Redis decorator integration tests.');
        }
        try {
            $redis = new Redis();
            $connected = $redis->connect((string)(getenv('CONTEXT_ENGINE_REDIS_HOST') ?: '127.0.0.1'), (int)(getenv('CONTEXT_ENGINE_REDIS_PORT') ?: 63809), 2.0);
            $authenticated = $redis->auth((string)(getenv('CONTEXT_ENGINE_REDIS_PASSWORD') ?: 'context_engine'));
        } catch (Throwable $e) {
            self::fail('Redis integration is enabled but service/configuration is unavailable: '.$e->getMessage());
        }
        self::assertTrue($connected);
        self::assertTrue($authenticated);
        $key = 'context-engine.integration.'.bin2hex(random_bytes(8));
        self::assertTrue($redis->set($key, 'tenant-space-cache'));
        self::assertSame('tenant-space-cache', $redis->get($key));
        $persistence = $redis->info('persistence');
        self::assertSame(1, $persistence['aof_enabled'] ?? 0, 'Redis must use AOF so cache survives container restart.');
        $redis->del($key);
    }
}
