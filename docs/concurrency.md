# Concorrência e backpressure

`BatchEmbeddingExecutor::execute(iterable $batches, EmbeddingProvider $provider): iterable<BatchEmbeddingResult>` é o contrato. A implementação real é `Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor`.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;

$serial = new FiberBatchEmbeddingExecutor(concurrency: 1);
$balanced = new FiberBatchEmbeddingExecutor(concurrency: 5);
$aggressive = new FiberBatchEmbeddingExecutor(concurrency: 20);
```

O argumento real chama-se `concurrency`, não `window`. Deve ser positivo. O executor inicia no máximo essa quantidade de lotes, cada um com sequence própria. Mesmo quando operações terminam fora de ordem, os resultados são entregues associados à sequence/chunks corretos e, na implementação atual, consumidos na ordem da janela.

`Future` e `FiberEventLoop` são detalhes internos desse namespace. Contratos, domínio, providers públicos e `RagPipeline` retornam tipos síncronos/iterables.

Após falha, a janela é drenada e resultados posteriores descartados. O executor não conhece banco; o pipeline persiste cada resultado serialmente.

- Janela 1: menor pressão e memória, menor paralelismo.
- Janela 5: equilíbrio inicial razoável, sujeito ao provider.
- Janela 20: maior memória, conexões e risco de rate limit.

Meça no ambiente real; o projeto ainda não publica benchmarks.
