# Cache

Cache usa `Psr\SimpleCache\CacheInterface` e permanece em decorators.

## CachedEmbeddingProvider

Construtor: `__construct(EmbeddingProvider $provider, CacheInterface $cache, int|DateInterval|null $ttl = null, string $namespace = 'context_embedding')`.

Chaves incluem hash do tenant, provider, hash do model, dimensions, fingerprint do espaço e hash exato do texto. No batch, hits são validados, textos repetidos são coalescidos, apenas misses únicos vão ao provider e a ordem original é reconstruída. Resposta parcial/cardinalidade errada não é armazenada.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Cache\CachedEmbeddingProvider;

$cachedEmbeddings = new CachedEmbeddingProvider(
    provider: $embeddingProvider,
    cache: $psr16Cache,
    ttl: 3600,
);
```

O tenant permanece na chave por isolamento e previsibilidade, mesmo que alguns embeddings pudessem ser compartilhados.

## CachedLanguageModel

Construtor: `__construct(CacheableLanguageModel $model, CacheInterface $cache, string $tenantId, string $promptVersion, bool $enabled = false, int|DateInterval|null $ttl = null, string $namespace = 'context_llm')`.

A chave inclui tenant, versão do prompt, fingerprint do modelo/geração e mensagens finais completas — system, user e contexto. O cache fica desativado por padrão porque respostas podem ser não determinísticas. Exceções não são armazenadas. O decorator não implementa streaming.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Cache\CachedLanguageModel;

$cachedModel = new CachedLanguageModel(
    model: $languageModel,
    cache: $psr16Cache,
    tenantId: 'tenant-42',
    promptVersion: 'support-v2',
    enabled: true,
    ttl: 300,
);
```

Invalide alterando namespace/versão, removendo chaves via PSR-16 ou limpando o backend. Redis é uma opção da aplicação; não há acoplamento do domínio.
