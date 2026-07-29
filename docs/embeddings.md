# Embeddings e EmbeddingSpace

## EmbeddingSpace

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

$space = new EmbeddingSpace(
    provider: 'openai',
    model: 'text-embedding-3-small',
    dimensions: 1536,
    revision: '2026-01',
    parameters: ['dimensions' => 1536],
);

echo $space->fingerprint();
```

Assinatura real:

```text
__construct(
    string $provider,
    string $model,
    int $dimensions,
    string $revision = '1',
    array $parameters = []
)
fingerprint(): string
```

Provider, model e revision não podem ser vazios; dimensions deve ser positiva. Parâmetros aceitam `null`, string, int, bool, float finito e arrays recursivos. Objetos, resources e floats não finitos são rejeitados.

O fingerprint SHA-256 usa representação canônica com tags de tipo, comprimento de strings, chaves associativas ordenadas e ordem preservada em listas. Assim `2`, `2.0` e `'2'` são diferentes, mas a ordem original de chaves associativas não altera o resultado.

Inclua somente parâmetros que mudam semanticamente o vetor. Não inclua API key, endpoint temporário, timeout, retries ou concorrência.

## Embedding

`Embedding::__construct(array $values, EmbeddingSpace $space)` exige lista não vazia de números finitos cuja cardinalidade seja exatamente `space->dimensions`. Métodos: `dimensions(): int` e `model(): string`.

## EmbeddingBatchRequest

Transporta `tenantId`, lista não vazia de textos, espaço esperado e metadata operacional escalar. O provider pode ignorar tenant; decorators usam esse contexto para isolamento.

Espaços com provider, modelo, dimensão, revisão ou parâmetros diferentes nunca devem ser misturados em um batch ou busca.
