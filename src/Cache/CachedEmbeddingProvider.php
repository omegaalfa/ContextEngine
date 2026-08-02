<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Cache;

use DateInterval;
use LogicException;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Psr\SimpleCache\CacheInterface;

final readonly class CachedEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private EmbeddingProvider $provider, private CacheInterface $cache, private null|int|DateInterval $ttl = null, private string $namespace = 'context_embedding') {}
    public function space(): EmbeddingSpace
    {
        return $this->provider->space();
    }
    public function embed(string $text, string $tenantId): Embedding
    {
        return $this->embedBatch(new EmbeddingBatchRequest($tenantId, [$text], $this->space()))[0];
    }
    /** @return list<Embedding> */
    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        if ($request->expectedSpace->fingerprint() !== $this->space()->fingerprint()) {
            throw new LogicException('Requested embedding space does not match provider.');
        } $input = $request->texts;
        $resolved = [];
        $missing = [];
        foreach ($input as $index => $text) {
            $key = $this->key($request->tenantId, $text);
            $cached = $this->cache->get($key);
            $embedding = $this->restore($cached);
            if ($embedding !== null) {
                $resolved[$index] = $embedding;
                continue;
            }
            $missing[$text][] = $index;
        }
        if ($missing !== []) {
            $uniqueTexts = array_keys($missing);
            $fresh = $this->provider->embedBatch(new EmbeddingBatchRequest($request->tenantId, $uniqueTexts, $this->space(), $request->metadata));
            if (count($fresh) !== count($uniqueTexts)) {
                throw new LogicException('Embedding provider returned a different batch size.');
            }
            foreach ($uniqueTexts as $offset => $text) {
                $embedding = $fresh[$offset];
                $this->assertSpace($embedding);
                $this->cache->set($this->key($request->tenantId, $text), ['values' => $embedding->values, 'space' => ['provider' => $embedding->space->provider, 'model' => $embedding->space->model, 'dimensions' => $embedding->space->dimensions, 'revision' => $embedding->space->revision, 'parameters' => $embedding->space->parameters]], $this->ttl);
                foreach ($missing[$text] as $index) {
                    $resolved[$index] = $embedding;
                }
            }
        }
        ksort($resolved);
        return array_values($resolved);
    }
    private function restore(mixed $cached): ?Embedding
    {
        if (is_array($cached) && isset($cached['values'], $cached['space']) && is_array($cached['values']) && is_array($cached['space'])) {
            $values = [];
            foreach ($cached['values'] as $value) {
                if (is_int($value) || is_float($value)) {
                    $values[] = $value;
                }
            }
            $space = $cached['space'];
            if ($values !== [] && count($values) === count($cached['values']) && isset($space['provider'],$space['model'],$space['dimensions'],$space['revision'],$space['parameters']) && is_string($space['provider']) && is_string($space['model']) && is_int($space['dimensions']) && is_string($space['revision']) && is_array($space['parameters'])) {
                $parameters = [];
                foreach ($space['parameters'] as $key => $value) {
                    if (is_string($key)) {
                        $parameters[$key] = $value;
                    }
                }
                $embedding = new Embedding($values, new EmbeddingSpace($space['provider'], $space['model'], $space['dimensions'], $space['revision'], $parameters));
                $this->assertSpace($embedding);
                return $embedding;
            }
        }
        return null;
    }
    private function assertSpace(Embedding $embedding): void
    {
        if ($embedding->space->fingerprint() !== $this->space()->fingerprint()) {
            throw new LogicException('Embedding provider returned an incompatible vector space.');
        }
    }
    private function key(string $tenantId, string $text): string
    {
        $space = $this->space();
        return $this->namespace . '.' . hash('sha256', $tenantId) . '.' . $space->provider . '.' . hash('sha256', $space->model) . '.' . $space->dimensions . '.' . $space->fingerprint() . '.' . hash('sha256', $text);
    }
}
