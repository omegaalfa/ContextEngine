<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Cache;

use DateInterval;
use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\CacheableLanguageModel;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Psr\SimpleCache\CacheInterface;

final readonly class CachedLanguageModel implements CacheableLanguageModel
{
    public function __construct(private CacheableLanguageModel $model, private CacheInterface $cache, private string $tenantId, private string $promptVersion, private bool $enabled = false, private null|int|DateInterval $ttl = null, private string $namespace = 'context_llm')
    {
        if (trim($tenantId) === '' || trim($promptVersion) === '') {
            throw new InvalidArgumentException('Tenant id and prompt version are required for response caching.');
        }
    }
    public function complete(array $messages): string
    {
        if (!$this->enabled) {
            return $this->model->complete($messages);
        }
        $key = $this->key($messages);
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }
        $content = $this->model->complete($messages);
        $this->cache->set($key, $content, $this->ttl);
        return $content;
    }
    public function generationFingerprint(): string
    {
        return $this->model->generationFingerprint();
    }
    /** @param list<ChatMessage> $messages */ private function key(array $messages): string
    {
        return $this->namespace . '.' . hash('sha256', implode("\0", [$this->tenantId, $this->promptVersion, $this->model->generationFingerprint(), json_encode(array_map(static fn (ChatMessage $m): array => [$m->role->value, $m->content], $messages), JSON_THROW_ON_ERROR)]));
    }
}
