# Cache

Cache usa `Psr\SimpleCache\CacheInterface` e permanece em decorators.

```text
INGESTÃO / RETRIEVAL

Texto + tenant + EmbeddingSpace
              ↓
   CachedEmbeddingProvider
              ↓
       existe no cache?
       ├── sim → valida e retorna
       └── não → provider real → valida → salva → retorna

GERAÇÃO DE RESPOSTA

Prompt + contexto + tenant + identidade do modelo
              ↓
      CachedLanguageModel
              ↓
       cache está habilitado e existe?
       ├── sim → retorna resposta armazenada
       └── não → LanguageModel → salva sucesso → retorna
```

O cache atua apenas em embeddings e respostas completas do LLM. O retrieval e a busca do `VectorStore` não são cacheados pelo código atual.

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

## Implementação PSR-16

Este pacote não inclui stores concretos de cache e não escolhe `omegaalfa/cache`. A aplicação deve fornecer um objeto que implemente `Psr\SimpleCache\CacheInterface`. Redis aparece no ambiente de integração para validar serviço autenticado e persistente, mas não existe aqui um adapter Redis PSR-16 pronto.

Não são documentados `ArrayStore`, `FileStore`, `RedisStore`, `ApcuStore` ou `NullStore`, porque namespaces, construtores, compatibilidade PSR-16 e forma de instalação não foram confirmados como parte deste repositório.
