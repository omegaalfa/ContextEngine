<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use DateInterval;
use Omegaalfa\ContextEngine\Cache\{CachedEmbeddingProvider,CachedLanguageModel};
use Omegaalfa\ContextEngine\Contract\{CacheableLanguageModel,EmbeddingProvider};
use Omegaalfa\ContextEngine\Embedding\{Embedding,EmbeddingBatchRequest,EmbeddingSpace};
use Omegaalfa\ContextEngine\Prompt\{ChatMessage,Role};
use Omegaalfa\ContextEngine\Rag\AnswerDelta;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class CacheAndStreamingTest extends TestCase
{
    public function testEmbeddingCacheDeduplicatesMissesRestoresOrderAndIsolatesTenant(): void
    {
        $cache = new ArrayCache();
        $embeddings = new class () implements EmbeddingProvider {
            public array $requests = [];
            public function space(): EmbeddingSpace
            {
                return new EmbeddingSpace('p', 'm', 1);
            } public function embed(string $text, string $tenantId): Embedding
            {
                return $this->embedBatch(new EmbeddingBatchRequest($tenantId, [$text], $this->space()))[0];
            } public function embedBatch(EmbeddingBatchRequest $request): array
            {
                $this->requests[] = $request->texts;
                return array_map(fn ($text) => new Embedding([strlen($text)], $this->space()), $request->texts);
            }
        };
        $cached = new CachedEmbeddingProvider($embeddings, $cache);
        $first = $cached->embedBatch(new EmbeddingBatchRequest('tenant-a', ['aa','bbb','aa'], $embeddings->space()));
        $second = $cached->embedBatch(new EmbeddingBatchRequest('tenant-a', ['aa','bbb','aa','cccc'], $embeddings->space()));
        $cached->embedBatch(new EmbeddingBatchRequest('tenant-b', ['aa'], $embeddings->space()));
        self::assertSame([["aa","bbb"],["cccc"],["aa"]], $embeddings->requests);
        self::assertSame([2.0,3.0,2.0], array_map(fn ($e) => $e->values[0], $first));
        self::assertSame([2.0,3.0,2.0,4.0], array_map(fn ($e) => $e->values[0], $second));
        $model = new class () implements CacheableLanguageModel {
            public int $calls = 0;
            public function complete(array $messages): string
            {
                $this->calls++;
                return 'answer';
            } public function generationFingerprint(): string
            {
                return 'model-fp';
            }
        };
        $cachedModel = new CachedLanguageModel($model, $cache, 'tenant', 'prompt-v1', enabled:true);
        $messages = [new ChatMessage(Role::USER, 'q')];
        self::assertSame('answer', $cachedModel->complete($messages));
        self::assertSame('answer', $cachedModel->complete($messages));
        self::assertSame(1, $model->calls);
    }
}
final class ArrayCache implements CacheInterface
{
    private array $data = [];
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    } public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    } public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    } public function clear(): bool
    {
        $this->data = [];
        return true;
    } public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield$key => $this->get($key, $default);
        }
    } public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }return true;
    } public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }return true;
    } public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
